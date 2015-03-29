<?php
namespace FutureProcess;

use Symfony\Component\Process\PhpExecutableFinder;

/**
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
class IntegrationTest extends \PHPUnit_Framework_TestCase
{
    private $phpExecutablePath;
    
    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        
        $finder = new PhpExecutableFinder;
        $this->phpExecutablePath = $finder->find();
    }
    
    public function testLateAbort()
    {
        $shell = new Shell;
        
        $process = $shell->startProcess(sprintf('%s -r %s',
            $this->phpExecutablePath,
            escapeshellarg('echo "Hello world!";')
        ));
        
        $process->getResult()->wait(0.5);
        $process->abort(new \Exception);
        $process->wait(0);
        $process->getResult()->wait(0); // ensure no Exception is thrown
        $this->assertSame(FutureProcess::STATUS_EXITED, $process->getStatus(false));
        
        $that = $this;
        $process->promise()->then(null, function () use ($that) {
            $that->fail();
        });
        $process->getResult()->promise()->then(null, function () use ($that) {
            $that->fail();
        });
    }
    
    public function testRepeatAbort()
    {
        $shell = new Shell;
        
        $process = $shell->startProcess(sprintf('%s -r %s',
            $this->phpExecutablePath,
            escapeshellarg('echo fread(fopen("php://stdin", "r"), 20);')
        ));
        
        $process->wait(0.1);
        $process->abort(new \Exception(null, 1));
        $process->abort(new \Exception(null, 2));
        
        try {
            $process->getResult()->wait(0);
            $this->fail();
        } catch (\Exception $e) {
            $this->assertSame(1, $e->getCode());
        }
    }
    
    public function testProcessGetPipeDescriptorValidation()
    {
        $shell = new Shell;
        
        $process = $shell->startProcess(sprintf('%s -r %s',
            $this->phpExecutablePath,
            escapeshellarg('usleep(100000); echo "Hello world!";')
        ));
        
        $process->writeToBuffer(0, 'Hello!');
        try {
            $process->writeToBuffer(5, 'Hello!');
            $this->fail();
        } catch (\RuntimeException $e) {
            
        }
        
        $process->readFromBuffer(1);
        try {
            $process->readFromBuffer(5);
            $this->fail();
        } catch (\RuntimeException $e) {
            
        }
        
        $process->getPipe(1);
        try {
            $process->getPipe(5);
            $this->fail();
        } catch (\RuntimeException $e) {
            
        }
    }
    
    public function testReadFromPipeAndBuffer()
    {
        $shell = new Shell;
        
        $process = $shell->startProcess(sprintf('%s -r %s',
            $this->phpExecutablePath,
            escapeshellarg('echo "Hello world!"; usleep(100000); echo "Goodbye world!";')
        ));
        
        $this->assertSame('Hello world!', fread($process->getPipe(1), 20));
        $this->assertSame(0, $process->getResult()->getExitCode());
        $this->assertSame('Goodbye world!', $process->readFromBuffer(1));
        $this->assertSame('', $process->getResult()->readFromBuffer(1));
    }
    
    public function testReadFromPipe()
    {
        $shell = new Shell;
        
        $process = $shell->startProcess(sprintf('%s -r %s',
            $this->phpExecutablePath,
            escapeshellarg('usleep(100000); echo "Hello world!";')
        ));
        
        $this->assertSame('Hello world!', fread($process->getPipe(1), 20));
    }
    
    public function testWriteToPipe()
    {
        $shell = new Shell;
        $process = $shell->startProcess(sprintf('%s -r %s',
            $this->phpExecutablePath,
            escapeshellarg('echo fread(fopen("php://stdin", "r"), 20);')
        ));
        
        fwrite($process->getPipe(0), "Hello world!\n");
        
        $result = $process->getResult()->wait(0.5);
        
        $this->assertSame(0, $result->getExitCode(), $result->readFromBuffer(2));
        $this->assertSame("Hello world!\n", $result->readFromBuffer(1));
    }
    
    public function testWriteToStdin()
    {
        $shell = new Shell;
        $process = $shell->startProcess(sprintf('%s -r %s',
            $this->phpExecutablePath,
            escapeshellarg('echo fread(fopen("php://stdin", "r"), 20);')
        ));
        
        $process->writeToBuffer(0, "Hello world!\n");
        
        $result = $process->getResult()->wait(0.5);
        
        $this->assertSame(0, $result->getExitCode(), $result->readFromBuffer(2));
        $this->assertSame("Hello world!\n", $result->readFromBuffer(1));
    }
    
    public function testWriteEmptyStringToStdin()
    {
        $shell = new Shell;
        $process = $shell->startProcess(
            "{$this->phpExecutablePath} -r "
            . escapeshellarg(implode("\n", array(
                '$stdin = fopen("php://stdin", "r");',
                'echo fread($stdin, 20);',
            )))
        );
        $process->promise()->then(function ($process) {
            $process->writeToBuffer(0, '0');
        });
        
        $result = $process->getResult()->wait(0.5);
        
        $this->assertSame(0, $result->getExitCode(), $result->readFromBuffer(2));
        $this->assertSame('0', $result->readFromBuffer(1));
    }
    
    public function testAbortRunningProcess()
    {
        $shell = new Shell;
        $process = $shell->startProcess($this->phpSleepCommand(0.5));
        
        $thrown = new ProcessAbortedException($process);
        $process->then(function ($process) use ($thrown) {
            $process->abort($thrown);
        });
        
        $process->wait(0.5); // this should not error
        
        try {
            $process->getResult()->wait(1);
            $this->fail('Expected Exception was not thrown');
        } catch (ProcessAbortedException $caught) {
            $this->assertSame($thrown, $caught);
        }
        
        $process->wait(0); // this should not error
        
        $that = $this;
        $processPromiseResolved = false;
        $process->then(
            function () use (&$processPromiseResolved) {
                $processPromiseResolved = true;
            },
            function () use ($that) {
                $that->fail();
            }
        );
        $this->assertTrue($processPromiseResolved);
        
        $resultPromiseRejected = false;
        $process->getResult()->promise()->then(
            function () use ($that) {
                $that->fail();
            },
            function () use (&$resultPromiseRejected) {
                $resultPromiseRejected = true;
            }
        );
        $this->assertTrue($resultPromiseRejected);
    }
    
    public function testAbortQueuedProcess()
    {
        $shell = new Shell;
        $shell->setProcessLimit(1);
        $process1 = $shell->startProcess($this->phpSleepCommand(0.5));
        $process2 = $shell->startProcess($this->phpSleepCommand(0.5));
        
        $this->assertSame(FutureProcess::STATUS_RUNNING, $process1->getStatus());
        $this->assertSame(FutureProcess::STATUS_QUEUED, $process2->getStatus());
        
        $thrown = new ProcessAbortedException($process2);
        $process2->abort($thrown);
        
        try {
            $process2->wait(0);
            $this->fail('Expected Exception was not thrown');
        } catch (ProcessAbortedException $caught) {
            $this->assertSame($thrown, $caught);
        }
        
        try {
            $process2->getResult()->wait(0);
            $this->fail('Expected Exception was not thrown');
        } catch (ProcessAbortedException $caught) {
            $this->assertSame($thrown, $caught);
        }
        
        $processPromiseError = null;
        $process2->then(null, function ($caught) use (&$processPromiseError) {
            $processPromiseError = $caught;
        });
        $this->assertSame($thrown, $processPromiseError);
        
        $resultPromiseError = null;
        $process2->getResult()->then(null, function ($caught) use (&$resultPromiseError) {
            $resultPromiseError = $caught;
        });
        $this->assertSame($thrown, $resultPromiseError);
    }
    
    public function testPHPHelloWorld()
    {
        $shell = new Shell;
        $command = "{$this->phpExecutablePath} -r \"echo 'Hello World';\"";
        $result = $shell->startProcess($command)->getResult()->wait(2);
        
        $this->assertSame(0, $result->getExitCode(), $result->readFromBuffer(2));
        $this->assertSame('Hello World', $result->readFromBuffer(1));
    }
    
    public function testExecuteCommandWithTimeout()
    {
        $shell = new Shell;
        $command = $this->phpSleepCommand(0.1);
        
        $startTime = microtime(true);
        try {
            $shell->startProcess($command)->getResult()->wait(0.05);
            $this->fail('Expected TimeoutException was not thrown');
        } catch (TimeoutException $e) {
            $runTime = microtime(true) - $startTime;
            $this->assertGreaterThanOrEqual(0.05, $runTime);
        }
        
        $result = $shell->startProcess($command)->getResult()->wait(0.5);
        $this->assertSame(0, $result->getExitCode());
    }
    
    public function testQueue()
    {
        $shell = new Shell;
        $shell->setProcessLimit(2);
        
        $process1 = $shell->startProcess($this->phpSleepCommand(0.5));
        $process2 = $shell->startProcess($this->phpSleepCommand(0.5));
        $process3 = $shell->startProcess($this->phpSleepCommand(0.5));
        
        usleep(100000);
        
        $this->assertSame(FutureProcess::STATUS_RUNNING, $process1->getStatus());
        $this->assertSame(FutureProcess::STATUS_RUNNING, $process2->getStatus());
        $this->assertSame(FutureProcess::STATUS_QUEUED, $process3->getStatus());
        
        $this->assertSame(FutureProcess::STATUS_RUNNING, $process3->wait(1)->getStatus());
    }
    
    public function testGetPid()
    {
        $shell = new Shell;
        
        $process = $shell->startProcess("{$this->phpExecutablePath} -r \"echo getmypid();\"");
        
        $reportedPid = $process->getPid();
        
        $actualPid = (int)$process->getResult()->readFromBuffer(1);
        
        $this->assertSame($actualPid, $reportedPid);
    }
    
    public function testLateStreamResolution()
    {
        $shell = new Shell;
        
        $result = $shell->startProcess("{$this->phpExecutablePath} -r \"echo 'hello';\"")
            ->getResult();
        
        $output = null;
        $result->then(function ($result) use (&$output) {
            $output = $result->readFromBuffer(1);
        });
        
        $result->wait(2);
        
        $this->assertSame('hello', $output);
    }
    
    public function testBufferFill()
    {
        $shell = new Shell;

        $result = $shell->startProcess("php -r \"echo str_repeat('x', 100000);\"")
            ->getResult();

        try {
            $result->wait(0.5);
        } catch (TimeoutException $e) {
            $this->fail('The child process is blocked. The output buffer is probably full.');
        }

        $this->assertSame(100000, strlen($result->readFromBuffer(1)));
    }
    
    public function testRepeatedReadCalls()
    {
        $shell = new Shell;
        $command = "{$this->phpExecutablePath} -r \"echo 'Hello World';\"";
        $result = $shell->startProcess($command)->getResult()->wait(2);
        
        $this->assertSame(0, $result->getExitCode(), $result->readFromBuffer(2));
        $this->assertSame('Hello World', $result->readFromBuffer(1));
        $this->assertSame('', $result->readFromBuffer(1));
    }
    
    private function phpSleepCommand($seconds)
    {
        $microSeconds = $seconds * 1000000;
        
        return "{$this->phpExecutablePath} -r \"usleep($microSeconds);\"";
    }
}
