<?php

namespace ArtemYurov\Autossh\Tests\Unit;

use ArtemYurov\Autossh\Services\ConnectionValidator;
use ArtemYurov\Autossh\Services\ProcessManager;
use ArtemYurov\Autossh\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class ConnectionValidatorTest extends TestCase
{
    public function test_is_port_accessible_delegates_to_process_manager(): void
    {
        $processManager = $this->createMock(ProcessManager::class);
        $processManager->expects($this->once())
            ->method('isPortInUse')
            ->with(5432, '127.0.0.1')
            ->willReturn(true);

        $validator = new ConnectionValidator($processManager);

        $this->assertTrue($validator->isPortAccessible(5432, '127.0.0.1'));
    }

    public function test_is_database_accessible_returns_true_on_success(): void
    {
        // Mock DB facade
        DB::shouldReceive('connection')
            ->once()
            ->with('test_connection')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->with('SELECT 1 as test')
            ->andReturn([['test' => 1]]);

        $validator = new ConnectionValidator();

        $this->assertTrue($validator->isDatabaseAccessible('test_connection'));
    }

    public function test_is_database_accessible_returns_false_on_exception(): void
    {
        DB::shouldReceive('connection')
            ->once()
            ->with('test_connection')
            ->andThrow(new \Exception('Connection failed'));

        $validator = new ConnectionValidator();

        $this->assertFalse($validator->isDatabaseAccessible('test_connection'));
    }

    public function test_validate_tunnel_returns_errors_when_process_not_running(): void
    {
        $processManager = $this->createMock(ProcessManager::class);
        $processManager->method('isProcessRunning')
            ->with(12345)
            ->willReturn(false);

        $validator = new ConnectionValidator($processManager);

        $result = $validator->validateTunnel(12345, 15432);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('not running', $result['errors'][0]);
    }

    public function test_validate_tunnel_returns_errors_when_not_ssh_process(): void
    {
        $processManager = $this->createMock(ProcessManager::class);
        $processManager->method('isProcessRunning')
            ->willReturn(true);
        $processManager->method('isSshProcess')
            ->willReturn(false);
        $processManager->method('getProcessInfo')
            ->willReturn(['name' => 'php']);

        $validator = new ConnectionValidator($processManager);

        $result = $validator->validateTunnel(12345, 15432);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('not SSH tunnel', $result['errors'][0]);
    }

    public function test_validate_tunnel_returns_errors_when_port_not_accessible(): void
    {
        $processManager = $this->createMock(ProcessManager::class);
        $processManager->method('isProcessRunning')
            ->willReturn(true);
        $processManager->method('isSshProcess')
            ->willReturn(true);
        $processManager->method('isPortInUse')
            ->willReturn(false);

        $validator = new ConnectionValidator($processManager);

        $result = $validator->validateTunnel(12345, 15432);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('not accessible', $result['errors'][0]);
    }

    public function test_validate_tunnel_checks_database_when_connection_name_provided(): void
    {
        $processManager = $this->createMock(ProcessManager::class);
        $processManager->method('isProcessRunning')
            ->willReturn(true);
        $processManager->method('isSshProcess')
            ->willReturn(true);
        $processManager->method('isPortInUse')
            ->willReturn(true);

        DB::shouldReceive('connection')
            ->once()
            ->with('test_db')
            ->andThrow(new \Exception('Database not accessible'));

        $validator = new ConnectionValidator($processManager);

        $result = $validator->validateTunnel(12345, 15432, 'test_db');

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('not accessible through tunnel', $result['errors'][0]);
    }

    public function test_validate_tunnel_succeeds_with_all_checks(): void
    {
        $processManager = $this->createMock(ProcessManager::class);
        $processManager->method('isProcessRunning')
            ->willReturn(true);
        $processManager->method('isSshProcess')
            ->willReturn(true);
        $processManager->method('isPortInUse')
            ->willReturn(true);

        DB::shouldReceive('connection')
            ->once()
            ->with('test_db')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->andReturn([['test' => 1]]);

        $validator = new ConnectionValidator($processManager);

        $result = $validator->validateTunnel(12345, 15432, 'test_db');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_wait_for_database_returns_true_when_database_available(): void
    {
        DB::shouldReceive('connection')
            ->once()
            ->with('test_db')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->andReturn([['test' => 1]]);

        $validator = new ConnectionValidator();

        $result = $validator->waitForDatabase('test_db', 3, 1);

        $this->assertTrue($result);
    }

    public function test_wait_for_database_retries_until_success(): void
    {
        $attempts = 0;

        DB::shouldReceive('connection')
            ->times(2)
            ->with('test_db')
            ->andReturnUsing(function () use (&$attempts) {
                $attempts++;
                if ($attempts < 2) {
                    throw new \Exception('Not ready');
                }
                return DB::getFacadeRoot();
            });

        DB::shouldReceive('select')
            ->once()
            ->andReturn([['test' => 1]]);

        $validator = new ConnectionValidator();

        // Use short delay for testing
        $result = $validator->waitForDatabase('test_db', 3, 0);

        $this->assertTrue($result);
        $this->assertEquals(2, $attempts);
    }

    public function test_wait_for_database_returns_false_after_max_attempts(): void
    {
        DB::shouldReceive('connection')
            ->times(3)
            ->with('test_db')
            ->andThrow(new \Exception('Connection failed'));

        $validator = new ConnectionValidator();

        $result = $validator->waitForDatabase('test_db', 3, 0);

        $this->assertFalse($result);
    }

    public function test_get_database_connection_error_returns_error_message(): void
    {
        DB::shouldReceive('connection')
            ->once()
            ->with('test_db')
            ->andThrow(new \Exception('Connection timeout'));

        $validator = new ConnectionValidator();

        $error = $validator->getDatabaseConnectionError('test_db');

        $this->assertEquals('Connection timeout', $error);
    }

    public function test_get_database_connection_error_returns_no_error_on_success(): void
    {
        DB::shouldReceive('connection')
            ->once()
            ->with('test_db')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->andReturn([['test' => 1]]);

        $validator = new ConnectionValidator();

        $error = $validator->getDatabaseConnectionError('test_db');

        $this->assertEquals('No error', $error);
    }
}
