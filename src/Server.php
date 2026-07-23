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
use Firehed\PhpLsp\Transport\TransportInterface;

final class Server
{
    private LifecycleHandler $lifecycleHandler;
    private DocumentManager $documentManager;

    /** @var list<HandlerInterface> */
    private array $handlers = [];

    /**
     * @param list<HandlerInterface> $additionalHandlers Registered after the
     *        built-in handlers, so they answer only methods none of those claim.
     */
    public function __construct(
        private TransportInterface $transport,
        ServerInfo $serverInfo,
        ?string $projectRoot = null,
        private readonly ParserService $parser = new ParserService(),
        array $additionalHandlers = [],
    ) {
        if ($projectRoot === null) {
            $cwd = getcwd();
            if ($cwd === false) {
                // @codeCoverageIgnoreStart
                throw new \LogicException('Unable to determine project root: getcwd() failed');
                // @codeCoverageIgnoreEnd
            }
            $projectRoot = $cwd;
        }

        $this->documentManager = new DocumentManager();
        $symbolIndex = new SymbolIndex();
        $indexer = new DocumentIndexer($this->parser, new SymbolExtractor(), $symbolIndex);
        $classLocator = new ComposerClassLocator($projectRoot);

        $classInfoFactory = new DefaultClassInfoFactory();
        $classRepository = new DefaultClassRepository($classInfoFactory, $classLocator, $this->parser);
        $functionRepository = new DefaultFunctionRepository();
        $memberResolver = new MemberResolver($classRepository);
        $typeResolver = new BasicTypeResolver($memberResolver, $functionRepository);
        $symbolResolver = new SymbolResolver(
            $this->parser,
            $classRepository,
            $memberResolver,
            $typeResolver,
            $functionRepository,
        );

        $negotiator = new CapabilityNegotiator($serverInfo);
        $this->lifecycleHandler = new LifecycleHandler($negotiator);
        $this->handlers[] = $this->lifecycleHandler;
        $this->handlers[] = new TextDocumentSyncHandler(
            $this->documentManager,
            $this->parser,
            $classRepository,
            $classInfoFactory,
            $indexer,
        );
        $this->handlers[] = new DefinitionHandler(
            $this->documentManager,
            $symbolResolver,
        );
        $this->handlers[] = new HoverHandler(
            $this->documentManager,
            $symbolResolver,
            $negotiator,
        );
        $this->handlers[] = new SignatureHelpHandler(
            $this->documentManager,
            $symbolResolver,
        );
        $this->handlers[] = new CompletionHandler(
            $this->documentManager,
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
        );

        foreach ($additionalHandlers as $handler) {
            $this->handlers[] = $handler;
        }
    }

    public function run(): int
    {
        while (($message = $this->transport->read()) !== null) {
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
                    $error = ResponseError::internalError($e->getMessage());
                } finally {
                    // The parse memo is scoped to one handled message — this loop
                    // is the only boundary that knows where that ends. Discarding
                    // it here is what keeps it from becoming the standing cache the
                    // Step 0 spike declined (0002-execution-plan.md, Section 8.5).
                    //
                    // In a finally so the scope closes on every exit from the
                    // dispatch, not just the ones that return normally: a handler
                    // that throws is fatal today, but S1.4 makes it survivable, and
                    // a memo that outlived a message would then be standing.
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
                $this->transport->write($response);
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
