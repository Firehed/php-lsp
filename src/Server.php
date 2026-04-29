<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

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
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use Firehed\PhpLsp\Completion\CompletionContextResolver;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
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

    public function __construct(
        private TransportInterface $transport,
        ServerInfo $serverInfo,
        ?string $projectRoot = null,
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
        $parser = new ParserService();
        $symbolIndex = new SymbolIndex();
        $indexer = new DocumentIndexer($parser, new SymbolExtractor(), $symbolIndex);
        $classLocator = new ComposerClassLocator($projectRoot);

        $classInfoFactory = new DefaultClassInfoFactory();
        $classRepository = new DefaultClassRepository($classInfoFactory, $classLocator, $parser);
        $memberResolver = new MemberResolver($classRepository);
        $typeResolver = new BasicTypeResolver($memberResolver);
        $memberAccessResolver = new MemberAccessResolver($typeResolver);
        $completionContextResolver = new CompletionContextResolver();

        $this->lifecycleHandler = new LifecycleHandler($serverInfo);
        $this->handlers[] = $this->lifecycleHandler;
        $this->handlers[] = new TextDocumentSyncHandler(
            $this->documentManager,
            $parser,
            $classRepository,
            $classInfoFactory,
            $indexer,
        );
        $this->handlers[] = new DefinitionHandler(
            $this->documentManager,
            $parser,
            $memberResolver,
            $classRepository,
            $memberAccessResolver,
        );
        $this->handlers[] = new HoverHandler(
            $this->documentManager,
            $parser,
            $classRepository,
            $memberResolver,
            $memberAccessResolver,
        );
        $this->handlers[] = new SignatureHelpHandler(
            $this->documentManager,
            $parser,
            $memberResolver,
            $memberAccessResolver,
        );
        $this->handlers[] = new CompletionHandler(
            $this->documentManager,
            $parser,
            $symbolIndex,
            $memberResolver,
            $classRepository,
            $typeResolver,
            $completionContextResolver,
        );
    }

    public function run(): int
    {
        while (($message = $this->transport->read()) !== null) {
            $result = null;
            $error = null;

            $handler = $this->findHandler($message->method);

            if ($handler !== null) {
                $result = $handler->handle($message);
            } elseif ($message instanceof RequestMessage) {
                $error = ResponseError::methodNotFound($message->method);
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
