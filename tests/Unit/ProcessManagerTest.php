<?php

namespace ArtemYurov\Autossh\Tests\Unit;

use ArtemYurov\Autossh\Services\ProcessManager;
use ArtemYurov\Autossh\Tests\TestCase;

class ProcessManagerTest extends TestCase
{
    public function test_is_process_running_with_valid_pid(): void
    {
        $manager = new ProcessManager();

        // Get current process PID (should always be running)
        $currentPid = getmypid();

        $this->assertTrue($manager->isProcessRunning($currentPid));
    }

    public function test_is_process_running_with_invalid_pid(): void
    {
        $manager = new ProcessManager();

        // Use impossible PID
        $this->assertFalse($manager->isProcessRunning(999999));
        $this->assertFalse($manager->isProcessRunning(0));
        $this->assertFalse($manager->isProcessRunning(-1));
    }

    public function test_get_process_info_with_current_process(): void
    {
        $manager = new ProcessManager();
        $currentPid = getmypid();

        $info = $manager->getProcessInfo($currentPid);

        $this->assertIsArray($info);
        $this->assertArrayHasKey('pid', $info);
        $this->assertArrayHasKey('name', $info);
        $this->assertArrayHasKey('command', $info);
        $this->assertEquals($currentPid, $info['pid']);
        $this->assertNotEmpty($info['name']);
    }

    public function test_get_process_info_with_invalid_pid(): void
    {
        $manager = new ProcessManager();

        $info = $manager->getProcessInfo(999999);

        $this->assertEmpty($info);
    }

    public function test_is_ssh_process_detects_ssh(): void
    {
        $manager = new ProcessManager();

        // Create a mock that simulates SSH process
        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('getProcessInfo');

        // We can't easily test this without mocking shell_exec
        // So we'll test the logic with a mocked getProcessInfo
        $mock = $this->getMockBuilder(ProcessManager::class)
            ->onlyMethods(['getProcessInfo'])
            ->getMock();

        // Test with SSH in process name
        $mock->method('getProcessInfo')
            ->willReturn([
                'pid' => 12345,
                'name' => 'ssh',
                'command' => '/usr/bin/ssh -L 15432:localhost:5432 user@host',
            ]);

        $this->assertTrue($mock->isSshProcess(12345));

        // Test with non-SSH process
        $mock2 = $this->getMockBuilder(ProcessManager::class)
            ->onlyMethods(['getProcessInfo'])
            ->getMock();

        $mock2->method('getProcessInfo')
            ->willReturn([
                'pid' => 12346,
                'name' => 'php',
                'command' => '/usr/bin/php artisan serve',
            ]);

        $this->assertFalse($mock2->isSshProcess(12346));
    }

    public function test_is_port_in_use(): void
    {
        $manager = new ProcessManager();

        // Test with port that's definitely not in use
        $this->assertFalse($manager->isPortInUse(65432, '127.0.0.1'));

        // Start a simple socket server for testing
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server !== false) {
            $socketName = stream_socket_get_name($server, false);
            [$host, $port] = explode(':', $socketName);

            // Port should be in use now
            $this->assertTrue($manager->isPortInUse((int)$port, $host));

            fclose($server);

            // Small delay to ensure port is released
            usleep(100000);

            // Port should be free now
            $this->assertFalse($manager->isPortInUse((int)$port, $host));
        } else {
            $this->markTestSkipped('Could not create test socket server');
        }
    }

    public function test_kill_process_with_invalid_pid(): void
    {
        $manager = new ProcessManager();

        // Trying to kill non-existent process should return true (already stopped)
        $this->assertTrue($manager->killProcess(999999));
    }

    public function test_find_process_by_port_returns_null_for_unused_port(): void
    {
        $manager = new ProcessManager();

        // Port that's definitely not in use
        $pid = $manager->findProcessByPort(65432);

        $this->assertNull($pid);
    }

    public function test_command_exists(): void
    {
        $manager = new ProcessManager();
        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('commandExists');
        $method->setAccessible(true);

        // Test with command that should exist
        $this->assertTrue($method->invoke($manager, 'ps'));

        // Test with command that shouldn't exist
        $this->assertFalse($method->invoke($manager, 'nonexistentcommandxyz123'));
    }

    public function test_process_info_contains_ssh_in_command(): void
    {
        $manager = new ProcessManager();

        // Mock ProcessManager to test SSH detection in command
        $mock = $this->getMockBuilder(ProcessManager::class)
            ->onlyMethods(['getProcessInfo'])
            ->getMock();

        // Test SSH in command path
        $mock->method('getProcessInfo')
            ->willReturn([
                'pid' => 12345,
                'name' => 'wrapper',
                'command' => '/usr/local/bin/ssh -N -L 15432:localhost:5432 user@host',
            ]);

        $this->assertTrue($mock->isSshProcess(12345));
    }

    public function test_empty_process_info_returns_false_for_ssh_check(): void
    {
        $manager = new ProcessManager();

        $mock = $this->getMockBuilder(ProcessManager::class)
            ->onlyMethods(['getProcessInfo'])
            ->getMock();

        $mock->method('getProcessInfo')
            ->willReturn([]);

        $this->assertFalse($mock->isSshProcess(12345));
    }
}
