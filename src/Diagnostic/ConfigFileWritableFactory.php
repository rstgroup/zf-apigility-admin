<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2014 Zend Technologies USA Inc. (http://www.zend.com)
 */
namespace ZF\Apigility\Admin\Diagnostic;

use Zend\ServiceManager\ServiceLocatorInterface;

class ConfigFileWritableFactory
{
    public function __invoke(ServiceLocatorInterface $services)
    {
        $config = $services->get('Config');

        $configPath = $config['zf-apigility-admin']['config-path'];

        return new ConfigFileWritable($configPath);
    }
}