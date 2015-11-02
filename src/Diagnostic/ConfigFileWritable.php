<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2014 Zend Technologies USA Inc. (http://www.zend.com)
 */
namespace ZF\Apigility\Admin\Diagnostic;

use ZendDiagnostics\Check\CheckInterface;
use ZendDiagnostics\Result\Failure;
use ZendDiagnostics\Result\Success;

class ConfigFileWritable implements CheckInterface
{
    protected $configPath;

    public function __construct($configPath)
    {
        $this->configPath = $configPath;
    }

    public function check()
    {
        $data = ['config-path' => $this->configPath];

        if (!is_writable($this->configPath)) {
            return new Failure('Config is not writable', $data);
        }
        return new Success('Config is writable', $data);
    }

    public function getLabel()
    {
        return 'Config File Writable';
    }
}