<?php

namespace ArtemYurov\Autossh\Tests\Unit;

use ArtemYurov\Autossh\Services\RetryManager;
use ArtemYurov\Autossh\Tests\TestCase;

class RetryManagerTest extends TestCase
{
    public function test_execute_succeeds_on_first_attempt(): void
    {
        $manager = new RetryManager();
        $calls = 0;

        $result = $manager->execute(function () use (&$calls) {
            $calls++;
            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(1, $calls);
    }

    public function test_execute_retries_on_exception(): void
    {
        $manager = new RetryManager();
        $manager->setMaxAttempts(3);
        $calls = 0;

        $result = $manager->execute(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new \Exception('Connection error');
            }
            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $calls);
    }

    public function test_execute_throws_exception_after_max_attempts(): void
    {
        $manager = new RetryManager();
        $manager->setMaxAttempts(3);
        $calls = 0;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Connection error');

        $manager->execute(function () use (&$calls) {
            $calls++;
            throw new \Exception('Connection error');
        });
    }

    public function test_execute_respects_should_retry_callback(): void
    {
        $manager = new RetryManager();
        $manager->setMaxAttempts(3);
        $calls = 0;

        $this->expectException(\RuntimeException::class);

        $manager->execute(
            function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('Non-retryable error');
            },
            function (\Exception $e) {
                // Only retry on connection errors
                return str_contains($e->getMessage(), 'connection');
            }
        );

        // Should fail immediately without retry
        $this->assertEquals(1, $calls);
    }

    public function test_execute_calls_reconnect_callback(): void
    {
        $manager = new RetryManager();
        $manager->setMaxAttempts(3);

        $reconnectCalls = 0;
        $manager->setReconnectCallback(function () use (&$reconnectCalls) {
            $reconnectCalls++;
        });

        $calls = 0;
        try {
            $manager->execute(function () use (&$calls) {
                $calls++;
                throw new \Exception('Connection error');
            });
        } catch (\Exception $e) {
            // Expected
        }

        // Reconnect should be called before each retry (2 times for 3 attempts)
        $this->assertEquals(2, $reconnectCalls);
    }

    public function test_set_delay(): void
    {
        $manager = new RetryManager();
        $manager->setDelay(5);

        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('delaySeconds');
        $property->setAccessible(true);

        $this->assertEquals(5, $property->getValue($manager));
    }

    public function test_with_exponential_backoff(): void
    {
        $manager = new RetryManager();
        $manager->withExponentialBackoff(true);

        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('exponentialBackoff');
        $property->setAccessible(true);

        $this->assertTrue($property->getValue($manager));
    }

    public function test_from_config(): void
    {
        $config = [
            'max_attempts' => 5,
            'delay' => 3,
            'exponential' => true,
        ];

        $manager = RetryManager::fromConfig($config);

        $reflection = new \ReflectionClass($manager);

        $maxAttempts = $reflection->getProperty('maxAttempts');
        $maxAttempts->setAccessible(true);
        $this->assertEquals(5, $maxAttempts->getValue($manager));

        $delay = $reflection->getProperty('delaySeconds');
        $delay->setAccessible(true);
        $this->assertEquals(3, $delay->getValue($manager));

        $exponential = $reflection->getProperty('exponentialBackoff');
        $exponential->setAccessible(true);
        $this->assertTrue($exponential->getValue($manager));
    }

    public function test_execute_with_reconnect_detects_connection_errors(): void
    {
        $manager = new RetryManager();
        $manager->setMaxAttempts(2);

        $reconnectCalls = 0;
        $calls = 0;

        try {
            $manager->executeWithReconnect(
                function () use (&$calls) {
                    $calls++;
                    throw new \Exception('Lost connection to database');
                },
                function () use (&$reconnectCalls) {
                    $reconnectCalls++;
                }
            );
        } catch (\Exception $e) {
            // Expected
        }

        // Should retry because it's a connection error
        $this->assertEquals(2, $calls);
        $this->assertEquals(1, $reconnectCalls);
    }

    public function test_execute_with_reconnect_does_not_retry_non_connection_errors(): void
    {
        $manager = new RetryManager();
        $manager->setMaxAttempts(3);

        $calls = 0;

        $this->expectException(\Exception::class);

        $manager->executeWithReconnect(
            function () use (&$calls) {
                $calls++;
                throw new \Exception('Syntax error');
            },
            function () {
                // Should not be called
            }
        );

        // Should not retry
        $this->assertEquals(1, $calls);
    }

    public function test_is_connection_error_detects_various_errors(): void
    {
        $manager = new RetryManager();
        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('isConnectionError');
        $method->setAccessible(true);

        // Test various connection error messages
        $connectionErrors = [
            'Lost connection to MySQL server',
            'Connection timed out',
            'Server has gone away',
            'Broken pipe',
            'Connection reset by peer',
            'Network is unreachable',
        ];

        foreach ($connectionErrors as $errorMessage) {
            $exception = new \Exception($errorMessage);
            $this->assertTrue(
                $method->invoke($manager, $exception),
                "Failed to detect connection error: $errorMessage"
            );
        }

        // Test non-connection errors
        $nonConnectionErrors = [
            'Syntax error',
            'Table not found',
            'Permission denied',
        ];

        foreach ($nonConnectionErrors as $errorMessage) {
            $exception = new \Exception($errorMessage);
            $this->assertFalse(
                $method->invoke($manager, $exception),
                "Incorrectly detected as connection error: $errorMessage"
            );
        }
    }
}
