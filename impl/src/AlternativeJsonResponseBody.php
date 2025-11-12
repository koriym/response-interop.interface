<?php
declare(strict_types=1);

namespace ResponseInterop\Impl;

use ResponseInterop\Interface\ResponseBodyContent;
use ResponseInterop\Interface\ResponseStruct;

// ============================================================================
// Proposal: Adding SenderInterface to improve testability and flexibility
// ============================================================================

/**
 * Alternative implementation with injectable sender.
 *
 * This demonstrates a proposed interface change:
 *   public function sendResponseBody(SenderInterface $sender) : void;
 */
class AlternativeJsonResponseBody implements ResponseBodyContent
{
    public const int DEFAULT_FLAGS = JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT
        | JSON_THROW_ON_ERROR;

    /**
     * @param array<mixed>|object $data
     * @param ?non-empty-string $type
     * @param int<1,max> $depth
     */
    public function __construct(
        public array|object $data,
        public ?string $type = null,
        public ?int $flags = null,
        public ?int $depth = null,
    ) {
    }

    public function prepareResponse(ResponseStruct $response) : void
    {
        $response->headers->setHeader(
            'content-type',
            $this->type ?? 'application/json'
        );
    }

    public function sendResponseBody(SenderInterface $sender) : void
    {
        $sender(json_encode(
            $this->data,
            $this->flags ?? static::DEFAULT_FLAGS,
            $this->depth ?? 512,
        ));
    }
}

// ============================================================================
// SenderInterface - Union Type Approach
// ============================================================================

/**
 * Abstraction for output mechanisms.
 *
 * Supports:
 * - string: In-memory content (JSON, HTML)
 * - resource: Streams (files, network)
 */
interface SenderInterface
{
    /**
     * @param string|resource $data
     *   string => echo or similar
     *   resource => fpassthru() for efficient streaming
     */
    public function __invoke(string|resource $data) : void;
}

// Default sender
class EchoSender implements SenderInterface
{
    public function __invoke(string|resource $data) : void
    {
        if (is_resource($data)) {
            rewind($data);
            fpassthru($data);  // Zero-copy kernel-level transfer
        } else {
            echo $data;
        }
    }
}

// Test helper
class BufferingSender implements SenderInterface
{
    /** @var array<string> */
    public array $captured = [];

    public function __invoke(string|resource $data) : void
    {
        if (is_resource($data)) {
            rewind($data);
            $this->captured[] = stream_get_contents($data);
        } else {
            $this->captured[] = $data;
        }
    }
}

// ============================================================================
// What We Gain:
// ============================================================================

/**
 * ## Current Approach (Original Design)
 *
 * ```php
 * public function sendResponseBody() : void
 * {
 *     echo json_encode($this->data, ...);
 * }
 * ```
 *
 * Problems:
 * - Hard-coded dependency on `echo`
 * - Testing requires ob_start()/ob_get_clean()
 * - Cannot customize output behavior
 * - Violates Single Responsibility Principle (SRP)
 * - Violates Dependency Inversion Principle (DIP)
 *
 *
 * ## Proposed Approach (This Design)
 *
 * ```php
 * public function sendResponseBody(SenderInterface $sender) : void
 * {
 *     $sender(json_encode($this->data, ...));
 * }
 * ```
 *
 * Benefits:
 * 1. **Testability** - Direct mock injection, no output buffering
 * 2. **Flexibility** - Custom senders (logging, compression, streaming)
 * 3. **Separation of Concerns** - "What to send" vs "How to send"
 * 4. **Dependency Injection** - Explicit dependencies
 *
 *
 * ## Architectural Improvements
 *
 * ### Before: ResponseBodyContent knows too much
 * ```
 * ResponseBodyContent
 *   ├─ Content generation (✓ appropriate)
 *   └─ Output mechanism    (✗ too low-level)
 * ```
 *
 * ### After: Clear separation
 * ```
 * ResponseBodyContent
 *   └─ Content generation only
 *
 * SenderInterface
 *   └─ Output mechanism
 * ```
 *
 *
 * ## Stream Efficiency: Why resource support matters
 *
 * ```php
 * // 1GB file with string-only interface
 * $content = file_get_contents('movie.mp4');  // 1GB in memory
 * $sender($content);
 * // Memory: 1GB+
 *
 * // 1GB file with resource support
 * $handle = fopen('movie.mp4', 'rb');
 * $sender($handle);  // Uses fpassthru() internally
 * // Memory: ~8KB (buffer only)
 * ```
 *
 * This is why Union Type (string|resource) is important.
 *
 *
 * ## Testing Comparison
 *
 * ### Original (output buffering)
 * ```php
 * ob_start();
 * $body->sendResponseBody();
 * $output = ob_get_clean();
 * assert($output === '{"hello":"world"}');
 * ```
 *
 * ### Proposed (direct injection)
 * ```php
 * $sender = new BufferingSender();
 * $body->sendResponseBody($sender);
 * assert($sender->captured[0] === '{"hello":"world"}');
 * ```
 *
 * The proposed approach is more explicit and doesn't rely on
 * output buffering magic.
 */

// ============================================================================
// Alternative: Double Dispatch Approach
// ============================================================================

/**
 * Double Dispatch version - More type-safe but more complex
 */
interface DoubleSenderInterface
{
    public function sendString(string $data) : void;
    public function sendStream(resource $stream) : void;
}

class DoubleEchoSender implements DoubleSenderInterface
{
    public function sendString(string $data) : void
    {
        echo $data;
    }

    public function sendStream(resource $stream) : void
    {
        rewind($stream);
        fpassthru($stream);
    }
}

class DoubleDispatchJsonResponseBody implements ResponseBodyContent
{
    public const int DEFAULT_FLAGS = JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT
        | JSON_THROW_ON_ERROR;

    public function __construct(
        public array|object $data,
        public ?string $type = null,
        public ?int $flags = null,
        public ?int $depth = null,
    ) {
    }

    public function prepareResponse(ResponseStruct $response) : void
    {
        $response->headers->setHeader(
            'content-type',
            $this->type ?? 'application/json'
        );
    }

    public function sendResponseBodyWith(DoubleSenderInterface $sender) : void
    {
        // Explicitly dispatch to sendString()
        $sender->sendString(json_encode(
            $this->data,
            $this->flags ?? static::DEFAULT_FLAGS,
            $this->depth ?? 512,
        ));
    }
}

// For FileResponseBody, would dispatch to sendStream():
// $sender->sendStream($this->file->fhandle());

/**
 * ## Comparison: Union Type vs Double Dispatch
 *
 * ### Union Type (Recommended for POC)
 * ```php
 * interface SenderInterface {
 *     public function __invoke(string|resource $data) : void;
 * }
 * ```
 * ✅ Simple - one method
 * ✅ Flexible - easy to implement
 * ⚠️ Runtime type checking neededrm
 *
 * ### Double Dispatch (Alternative)
 * ```php
 * interface SenderInterface {
 *     public function sendString(string $data) : void;
 *     public function sendStream(resource $stream) : void;
 * }
 * ```
 * ✅ Type-safe - compile-time checking
 * ✅ Explicit - clear intent
 * ⚠️ More methods - interface grows with new types
 * ⚠️ All implementations must support both
 *
 * For a POC focusing on the core concept, Union Type is clearer.
 * For production with many data types, Double Dispatch scales better.
 */

// ============================================================================
// Simple Usage Examples
// ============================================================================

// Example 1: Basic usage
function example_basic() : void
{
    $body = new AlternativeJsonResponseBody(['hello' => 'world']);
    $sender = new EchoSender();
    $body->sendResponseBody($sender);
    // Output: {"hello":"world"}
}

// Example 2: Testing
function example_test() : void
{
    $body = new AlternativeJsonResponseBody(['status' => 'ok']);
    $sender = new BufferingSender();
    $body->sendResponseBody($sender);
    assert($sender->captured[0] === '{"status":"ok"}');
}

// Example 3: File streaming (conceptual - for FileResponseBody)
function example_file() : void
{
    $handle = fopen('large-file.dat', 'rb');
    $sender = new EchoSender();
    $sender($handle);  // Efficiently streams without loading into memory
    fclose($handle);
}
