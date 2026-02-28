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
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Protocol\ResponseError;
use Firehed\PhpLsp\Protocol\ResponseMessage;
use Firehed\PhpLsp\Transport\TransportInterface;
use Firehed\PhpLsp\TypeInference\PhpStanTypeInferenceService;

final class Server
{
    private LifecycleHandler $lifecycleHandler;
    private DocumentManager $documentManager;
    private bool $initialized = false;

    /** @var list<HandlerInterface> */
    private array $handlers = [];

    public function __construct(
        private TransportInterface $transport,
        private ServerInfo $serverInfo,
    ) {
        $this->documentManager = new DocumentManager();
        $this->lifecycleHandler = new LifecycleHandler($this->serverInfo);
    }

    public function run(): int
    {
        while (($message = $this->transport->read()) !== null) {
            $result = null;
            $error = null;

            // Handle initialize specially to set up handlers with project root
            if ($message->method === 'initialize' && $message instanceof RequestMessage) {
                $result = $this->handleInitialize($message);
            } else {
                $handler = $this->findHandler($message->method);

                if ($handler !== null) {
                    $result = $handler->handle($message);
                } elseif ($message instanceof RequestMessage) {
                    $error = ResponseError::methodNotFound($message->method);
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

    /**
     * Handle the initialize request and set up handlers with the project root.
     *
     * @return array{capabilities: array<string, mixed>, serverInfo: array{name: string, version: string}}
     */
    private function handleInitialize(RequestMessage $message): array
    {
        $params = $message->params ?? [];

        // Extract project root from initialize params
        $projectRoot = null;
        if (isset($params['rootUri']) && is_string($params['rootUri'])) {
            // Convert file:// URI to path
            $projectRoot = preg_replace('#^file://#', '', $params['rootUri']);
        } elseif (isset($params['rootPath']) && is_string($params['rootPath'])) {
            $projectRoot = $params['rootPath'];
        }

        // Fall back to cwd if not provided
        $projectRoot ??= getcwd() ?: null;

        // Now initialize all handlers with the correct project root
        $this->initializeHandlers($projectRoot);
        $this->initialized = true;

        // Return capabilities (delegated to lifecycle handler for consistency)
        /** @var array{capabilities: array<string, mixed>, serverInfo: array{name: string, version: string}} */
        $result = $this->lifecycleHandler->handle($message);
        return $result;
    }

    private function initializeHandlers(?string $projectRoot): void
    {
        $parser = new ParserService();
        $symbolIndex = new SymbolIndex();
        $indexer = new DocumentIndexer($parser, new SymbolExtractor(), $symbolIndex);
        $classLocator = $projectRoot !== null ? new ComposerClassLocator($projectRoot) : null;
        $typeInference = new PhpStanTypeInferenceService($projectRoot);

        $this->handlers = [];
        $this->handlers[] = $this->lifecycleHandler;
        $this->handlers[] = new TextDocumentSyncHandler($this->documentManager, $indexer, $typeInference);
        $this->handlers[] = new DefinitionHandler($this->documentManager, $parser, $symbolIndex, $classLocator, $typeInference);
        $this->handlers[] = new HoverHandler($this->documentManager, $parser, $classLocator, $typeInference);
        $this->handlers[] = new SignatureHelpHandler($this->documentManager, $parser, $classLocator, $typeInference);
        $this->handlers[] = new CompletionHandler($this->documentManager, $parser, $classLocator, $typeInference);
    }

    private function findHandler(string $method): ?HandlerInterface
    {
        // Before initialization, only lifecycle handler is available
        if (!$this->initialized && $method !== 'initialized') {
            if ($this->lifecycleHandler->supports($method)) {
                return $this->lifecycleHandler;
            }
            return null;
        }

        foreach ($this->handlers as $handler) {
            if ($handler->supports($method)) {
                return $handler;
            }
        }
        return null;
    }
}
