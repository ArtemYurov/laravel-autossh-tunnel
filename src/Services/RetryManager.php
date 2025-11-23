<?php

namespace ArtemYurov\Autossh\Services;

/**
 * Service for managing retry logic when working with SSH tunnels
 *
 * Provides configurable retry strategies for tunnel operations
 */
class RetryManager
{
    /**
     * Default maximum number of attempts
     */
    protected int $maxAttempts = 3;

    /**
     * Delay between attempts in seconds
     */
    protected int $delaySeconds = 2;

    /**
     * Use exponential backoff
     */
    protected bool $exponentialBackoff = false;

    /**
     * Callback for reconnection on error
     *
     * @var callable|null
     */
    protected $reconnectCallback = null;

    /**
     * Set maximum number of attempts
     */
    public function setMaxAttempts(int $attempts): self
    {
        $this->maxAttempts = $attempts;
        return $this;
    }

    /**
     * Set delay between attempts
     */
    public function setDelay(int $seconds): self
    {
        $this->delaySeconds = $seconds;
        return $this;
    }

    /**
     * Enable exponential backoff
     */
    public function withExponentialBackoff(bool $enabled = true): self
    {
        $this->exponentialBackoff = $enabled;
        return $this;
    }

    /**
     * Set callback for reconnection
     *
     * Callback will be called before each retry attempt
     *
     * @param callable $callback function(): void
     */
    public function setReconnectCallback(callable $callback): self
    {
        $this->reconnectCallback = $callback;
        return $this;
    }

    /**
     * Execute operation with automatic retry attempts
     *
     * @param callable $operation Operation to execute
     * @param callable|null $shouldRetry Function to check if retry needed for exception
     * @return mixed Successful operation result
     * @throws \Exception Last exception if all attempts failed
     */
    public function execute(callable $operation, ?callable $shouldRetry = null)
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                // Execute operation
                return $operation();

            } catch (\Exception $e) {
                $lastException = $e;

                // Check if retry needed for this exception
                if ($shouldRetry !== null && !$shouldRetry($e)) {
                    throw $e; // Non-retryable exception
                }

                // If this is last attempt - throw exception
                if ($attempt >= $this->maxAttempts) {
                    throw $e;
                }

                // Call reconnect callback if set
                if ($this->reconnectCallback !== null) {
                    ($this->reconnectCallback)();
                }

                // Wait before next attempt
                $this->sleep($attempt);
            }
        }

        // Shouldn't reach here, but just in case
        throw $lastException ?? new \RuntimeException('All retry attempts failed');
    }

    /**
     * Execute operation with reconnection on connection error
     *
     * Automatically detects connection-related errors and reconnects
     *
     * @param callable $operation
     * @param callable $reconnect Function for reconnection
     * @return mixed
     */
    public function executeWithReconnect(callable $operation, callable $reconnect)
    {
        $this->setReconnectCallback($reconnect);

        return $this->execute($operation, function (\Exception $e) {
            // Retry only for connection-related errors
            return $this->isConnectionError($e);
        });
    }

    /**
     * Check if exception is connection error
     *
     * @param \Exception $e
     * @return bool
     */
    protected function isConnectionError(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        $connectionErrors = [
            'connection',
            'lost connection',
            'gone away',
            'broken pipe',
            'reset by peer',
            'timeout',
            'timed out',
            'network',
            'unreachable',
        ];

        foreach ($connectionErrors as $error) {
            if (str_contains($message, $error)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delay between attempts
     *
     * @param int $attempt Current attempt number
     */
    protected function sleep(int $attempt): void
    {
        if ($this->exponentialBackoff) {
            // Exponential backoff: 2, 4, 8, 16...
            $delay = $this->delaySeconds * pow(2, $attempt - 1);
        } else {
            // Linear delay
            $delay = $this->delaySeconds;
        }

        sleep($delay);
    }

    /**
     * Create RetryManager from configuration
     *
     * @param array $config ['max_attempts' => int, 'delay' => int, 'exponential' => bool]
     * @return static
     */
    public static function fromConfig(array $config): static
    {
        $manager = new static();

        if (isset($config['max_attempts'])) {
            $manager->setMaxAttempts($config['max_attempts']);
        }

        if (isset($config['delay'])) {
            $manager->setDelay($config['delay']);
        }

        if (isset($config['exponential'])) {
            $manager->withExponentialBackoff($config['exponential']);
        }

        return $manager;
    }
}
