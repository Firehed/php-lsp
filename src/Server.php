<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

use Firehed\PhpLsp\Capability\CapabilityNegotiator;
use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\ClassCandidates;
use Firehed\PhpLsp\Completion\FunctionCandidates;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Completion\NamespaceCandidates;
use Firehed\PhpLsp\Completion\VariableCandidates;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Handler\DefinitionHandler;
use Firehed\PhpLsp\Handler\HandlerInterface;
use Firehed\PhpLsp\Handler\HoverHandler;
use Firehed\PhpLsp\Handler\LifecycleHandler;
use Firehed\PhpLsp\Handler\SignatureHelpHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\NamespaceCatalogFactory;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\DefaultFunctionRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Protocol\ResponseError;
use Firehed\PhpLsp\Protocol\ResponseMessage;
use Firehed\PhpLsp\Transport\EndOfStream;
use Firehed\PhpLsp\Transport\MalformedFrame;
use Firehed\PhpLsp\Transport\TransportInterface;

final class Server
{
    /** @var list<HandlerInterface> */
    private readonly array $handlers;

    /**
     * @param LifecycleHandler $lifecycleHandler Named separately because the
     *        loop asks it for the lifecycle gate and the exit code, which are
     *        not dispatch. It is prepended to the dispatch list here rather
     *        than passed in twice, so the instance the gate consults and the
     *        instance that handles `initialize`/`shutdown` cannot diverge.
     * @param list<HandlerInterface> $handlers Consulted after it, in order;
     *        the first that supports a method answers it.
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly LifecycleHandler $lifecycleHandler,
        array $handlers,
        private readonly ParserService $parser,
    ) {
        $this->handlers = [$lifecycleHandler, ...$handlers];
    }

    /**
     * Wires the server against a project on disk.
     *
     * Construction lives here rather than in the constructor so the constructor
     * stays injectable: the dispatch loop can then be exercised against a
     * handler set a test chooses, without standing up the whole project.
     */
    public static function forProject(
        TransportInterface $transport,
        ServerInfo $serverInfo,
        ?string $projectRoot = null,
        ParserService $parser = new ParserService(),
    ): self {
        if ($projectRoot === null) {
            $cwd = getcwd();
            if ($cwd === false) {
                // @codeCoverageIgnoreStart
                throw new \LogicException('Unable to determine project root: getcwd() failed');
                // @codeCoverageIgnoreEnd
            }
            $projectRoot = $cwd;
        }

        $documentManager = new DocumentManager();
        $symbolIndex = new SymbolIndex();
        $indexer = new DocumentIndexer($parser, new SymbolExtractor(), $symbolIndex);
        $classLocator = new ComposerClassLocator($projectRoot);

        $classInfoFactory = new DefaultClassInfoFactory();
        $classRepository = new DefaultClassRepository($classInfoFactory, $classLocator, $parser);
        $functionRepository = new DefaultFunctionRepository();
        $memberResolver = new MemberResolver($classRepository);
        $typeResolver = new BasicTypeResolver($memberResolver, $functionRepository);
        $symbolResolver = new SymbolResolver(
            $parser,
            $classRepository,
            $memberResolver,
            $typeResolver,
            $functionRepository,
        );

        $negotiator = new CapabilityNegotiator($serverInfo);
        $lifecycleHandler = new LifecycleHandler($negotiator);

        $handlers = [
            new TextDocumentSyncHandler(
                $documentManager,
                $parser,
                $classRepository,
                $classInfoFactory,
                $indexer,
            ),
            new DefinitionHandler(
                $documentManager,
                $symbolResolver,
            ),
            new HoverHandler(
                $documentManager,
                $symbolResolver,
                $negotiator,
            ),
            new SignatureHelpHandler(
                $documentManager,
                $symbolResolver,
            ),
            new CompletionHandler(
                $documentManager,
                $symbolResolver,
                new ClassCandidates($symbolIndex, $symbolResolver),
                new NamespaceCandidates(
                    NamespaceCatalogFactory::forProject($symbolIndex, $projectRoot),
                    $symbolResolver,
                ),
                new FunctionCandidates($symbolResolver, $negotiator),
                new KeywordCandidates(),
                new VariableCandidates($symbolResolver),
                new MemberCandidates($symbolResolver, $negotiator),
                new NamedArgumentCandidates(),
                new BuiltinTypeCandidates(),
            ),
        ];

        return new self($transport, $lifecycleHandler, $handlers, $parser);
    }

    public function run(): int
    {
        while (true) {
            $message = $this->transport->read();

            if ($message instanceof EndOfStream) {
                break;
            }

            if ($message instanceof MalformedFrame) {
                // The id cannot be recovered from a frame that would not decode,
                // so it is answered with the JSON-RPC null id (RFC 1 §9). The
                // loop continues: one bad frame must not end the session.
                $this->transport->write(ResponseMessage::error(null, $message->error));
                continue;
            }

            $result = null;

            // Lifecycle gate (RFC 1 §4.8): a message not permitted in the current
            // state is answered with the lifecycle error and never dispatched. A
            // gated notification has no id, so its error is simply dropped.
            $error = $this->lifecycleHandler->lifecycleErrorFor($message);

            if ($error === null) {
                $handler = $this->findHandler($message->method);

                try {
                    if ($handler !== null) {
                        $result = $handler->handle($message);
                    } elseif ($message instanceof RequestMessage) {
                        $error = ResponseError::methodNotFound($message->method);
                    }
                } catch (\Throwable $e) {
                    // A failing handler must not take the read loop down with it
                    // (RFC 1 §9): an editor session that dies on one bad request
                    // loses all unsaved server state. Notifications have no id to
                    // answer, so their failure is contained and dropped.
                    //
                    // Forwarding the raw message crosses no trust boundary: the
                    // client is the editor that spawned this process over its own
                    // stdio pipe, and it already has the server's whole filesystem
                    // view. Paths it may carry are the user's own, bound for the
                    // user's own LSP log, which is what makes an unreproducible
                    // crash diagnosable. `message` stays generic; per [LSP] "Base
                    // Protocol", ResponseError.data is where detail belongs.
                    $error = ResponseError::internalError($e->getMessage());
                } finally {
                    // The parse memo is scoped to one handled message — this loop
                    // is the only boundary that knows where that ends. Discarding
                    // it here is what keeps it from becoming the standing cache the
                    // Step 0 spike declined (0002-execution-plan.md, Section 8.5).
                    //
                    // In a finally so the scope closes on every exit from the
                    // dispatch, not just the ones that return normally: a handler
                    // that throws is caught just above and the loop keeps serving,
                    // so a memo outliving its message would become standing.
                    $this->parser->discardScopedParses();
                }
            }

            // Send response for requests (not notifications)
            if ($message instanceof RequestMessage) {
                if ($error !== null) {
                    $response = ResponseMessage::error($message->id, $error);
                } else {
                    $response = ResponseMessage::success($message->id, $result);
                }
                $this->writeResponse($message->id, $response);
            }

            // Check for exit
            $exitCode = $this->lifecycleHandler->getExitCode();
            if ($exitCode !== null) {
                $this->transport->close();
                return $exitCode;
            }
        }

        $this->transport->close();
        return 1;
    }

    /**
     * A result a handler produced but the encoder cannot represent fails here
     * rather than in `handle()`, so the dispatch catch never sees it. It is
     * still a handler failure and must not take the read loop down with it
     * (RFC 1 §9); text lifted from a file that is not valid UTF-8 reaches the
     * writer exactly this way.
     *
     * The replacement carries only the exception message and the decoded id,
     * both of which are known-encodable, so answering cannot fail the same way.
     * The frame is encoded before any bytes are written, so the failed response
     * leaves nothing half-written on the wire.
     *
     * The forwarded message discloses nothing: json_encode's failures come from
     * PHP's fixed json_last_error_msg table ("Malformed UTF-8 characters…",
     * "Recursion detected", …), which interpolate none of the value that failed.
     */
    private function writeResponse(int|string $id, ResponseMessage $response): void
    {
        try {
            $this->transport->write($response);
        } catch (\JsonException $e) {
            $this->transport->write(
                ResponseMessage::error($id, ResponseError::internalError($e->getMessage())),
            );
        }
    }

    private function findHandler(string $method): ?HandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($method)) {
                return $handler;
            }
        }
        return null;
    }
}
