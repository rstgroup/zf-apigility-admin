<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2013 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\Apigility\Admin\Model;

use ZF\Apigility\Admin\Exception;
use ZF\Configuration\ConfigResource;
use ZF\Configuration\ModuleUtils;

class AuthorizationModel
{
    /**
     * @var ConfigResource
     */
    protected $configResource;

    /**
     * @var string
     */
    protected $module;

    /**
     * @var ModuleEntity
     */
    protected $moduleEntity;

    /**
     * @var string
     */
    protected $modulePath;

    /**
     * @var ModuleUtils
     */
    protected $modules;

    /**
     * @param  ModuleEntity $moduleEntity
     * @param  ModuleUtils $modules
     * @param  ConfigResource $config
     */
    public function __construct(ModuleEntity $moduleEntity, ModuleUtils $modules, ConfigResource $config)
    {
        $this->module         = $moduleEntity->getName();
        $this->moduleEntity   = $moduleEntity;
        $this->modules        = $modules;
        $this->configResource = $config;
        $this->modulePath     = $modules->getModulePath($this->module);
    }

    /**
     * Fetch authorization list for a given module by version
     * 
     * @param int $version 
     * @return AuthorizationEntity
     */
    public function fetch($version = 1)
    {
        $allConfig = $this->configResource->fetch(true);
        if (!isset($allConfig['zf-mvc-auth'])
            || !isset($allConfig['zf-mvc-auth']['authorization'])
        ) {
            // Determine existing services, and return defaults for them
            return $this->createDefaultPrivileges($version, $allConfig);
        }

        $config = $allConfig['zf-mvc-auth']['authorization'];

        // Strip out any services that are not for the current $version
        $config = $this->filterServicesByVersion($config, $version);

        // Ensure REST services are mapped correctly, and create entity
        $config = $this->remapRestServiceNamesForPayload($config);
        $entity = new AuthorizationEntity($config);

        // Determine if we have any services missing, and create default 
        // entries for them
        $this->injectServicesWithoutPrivileges($entity, $version, $allConfig);

        return $entity;
    }

    /**
     * Update the authorization list for a given module by version
     * 
     * @param array $privileges 
     * @param int $version 
     * @return AuthorizationEntity
     */
    public function update(array $privileges, $version = 1)
    {
        $toStore = $this->remapRestServiceNamesForStorage($privileges);
        $this->configResource->patchKey(array('zf-mvc-auth', 'authorization'), $toStore);
        return $this->fetch($version);
    }

    /**
     * Create default privileges for all services of a specific version in the module
     * 
     * @param int $version 
     * @param array $config 
     * @return AuthorizationEntity
     */
    protected function createDefaultPrivileges($version, array $config)
    {
        $entity = new AuthorizationEntity();

        if (isset($config['zf-rest'])
            && is_array($config['zf-rest'])
        ) {
            $this->createDefaultPrivilegesForRestServices(array_keys($config['zf-rest']), $entity, $version);
        }

        if (isset($config['zf-rpc'])
            && is_array($config['zf-rpc'])
        ) {
            $this->createDefaultPrivilegesForRpcServices($config['zf-rpc'], $entity, $config, $version);
        }

        return $entity;
    }

    /**
     * Create default privileges for a list of REST services of the specified version
     * 
     * @param array $services 
     * @param AuthorizationEntity $entity 
     * @param int $version 
     */
    protected function createDefaultPrivilegesForRestServices(array $services, AuthorizationEntity $entity, $version)
    {
        foreach ($services as $serviceName) {
            if (!preg_match('/' . preg_quote('\\') . 'V' . $version . preg_quote('\\') . '/', $serviceName)) {
                continue;
            }
            $entity->addRestService($serviceName, $entity::TYPE_RESOURCE);
            $entity->addRestService($serviceName, $entity::TYPE_COLLECTION);
        }
    }

    /**
     * Create default privileges for a list of RPC services of the specified version
     * 
     * @param array $services 
     * @param AuthorizationEntity $entity 
     * @param array $config Used to determine action associated with RPC service (via route config)
     * @param int $version 
     */
    protected function createDefaultPrivilegesForRpcServices(array $services, AuthorizationEntity $entity, array $config, $version)
    {
        foreach ($services as $serviceName => $serviceConfig) {
            if (!preg_match('/' . preg_quote('\\') . 'V' . $version . preg_quote('\\') . '/', $serviceName)) {
                continue;
            }
            $action = $this->discoverActionForRpcService($serviceName, $serviceConfig, $config);
            $entity->addRpcService($serviceName, $action); 
        }
    }

    /**
     * Attempt to identify the action associated with an RPC service
     *
     * Looks for an "action" default in the route options associated with the
     * RPC service.
     *
     * If no route name is provided, or no "action" default is found, returns 
     * "index".
     * 
     * @param string $serviceName 
     * @param array $serviceConfig 
     * @param array $config 
     * @return string
     */
    protected function discoverActionForRpcService($serviceName, array $serviceConfig, array $config)
    {
        if (!isset($serviceConfig['route_name'])) {
            return 'index';
        }

        $route = $serviceConfig['route_name'];

        if (!isset($config['router'])
            || !isset($config['router']['routes'])
            || !isset($config['router']['routes'][$route])
            || !isset($config['router']['routes'][$route]['options'])
            || !isset($config['router']['routes'][$route]['options']['defaults'])
            || !isset($config['router']['routes'][$route]['options']['defaults']['action'])
        ) {
            return 'index';
        }

        return $config['router']['routes'][$route]['options']['defaults']['action'];
    }

    /**
     * Removes any services that do not match the current version
     * 
     * @param array $config 
     * @param int $version 
     * @return array
     */
    protected function filterServicesByVersion(array $config, $version)
    {
        foreach ($config as $serviceName => $privileges) {
            if (!preg_match('/' . preg_quote('\\') . 'V' . $version . preg_quote('\\') . '/', $serviceName)) {
                unset($config[$serviceName]);
            }
        }
        return $config;
    }

    /**
     * Translate service names for REST services to match the payload expectations.
     * 
     * @param array $config 
     * @return array
     */
    protected function remapRestServiceNamesForPayload(array $config)
    {
        foreach ($config as $key => $value) {
            // Replace keys to match what the API is going to send back and forth
            if (preg_match('/::(resource|collection)$/', $key)) {
                $newKey = preg_replace('/(::)(resource|collection)$/', '$1__$2__', $key);
                $config[$newKey] = $value;
                unset($config[$key]);
                continue;
            }
        }
        return $config;
    }

    /**
     * Translate service names for REST services to match the storage expectations.
     * 
     * @param array $config 
     * @return array
     */
    protected function remapRestServiceNamesForStorage(array $config)
    {
        foreach ($config as $key => $value) {
            // Replace keys to match what the API is going to send back and forth
            if (preg_match('/::__(resource|collection)__$/', $key)) {
                $newKey = preg_replace('/(::)__(resource|collection)__$/', '$1$2', $key);
                $config[$newKey] = $value;
                unset($config[$key]);
                continue;
            }
        }
        return $config;
    }

    /**
     * Identify services in the current version without authorization configuration and inject them into the entity
     * 
     * @param AuthorizationEntity $entity 
     * @param int $version 
     * @param array $config 
     */
    protected function injectServicesWithoutPrivileges(AuthorizationEntity $entity, $version, array $config)
    {
        $services = $this->getBaseServiceNamesFromEntity($entity);
        if (isset($config['zf-rest'])
            && is_array($config['zf-rest'])
        ) {
            $missingServices = array();
            foreach (array_keys($config['zf-rest']) as $serviceName) {
                if (!preg_match('/' . preg_quote('\\') . 'V' . $version . preg_quote('\\') . '/', $serviceName)) {
                    continue;
                }
                if (in_array($serviceName, $services)) {
                    continue;
                }
                $missingServices[] = $serviceName;
            }
            $this->createDefaultPrivilegesForRestServices($missingServices, $entity, $version);
        }

        if (isset($config['zf-rpc'])
            && is_array($config['zf-rpc'])
        ) {
            $missingServices = array();
            foreach ($config['zf-rpc'] as $serviceName => $serviceConfig) {
                if (!preg_match('/' . preg_quote('\\') . 'V' . $version . preg_quote('\\') . '/', $serviceName)) {
                    continue;
                }
                if (in_array($serviceName, $services)) {
                    continue;
                }
                $missingServices[$serviceName] = $serviceConfig;
            }
            $this->createDefaultPrivilegesForRpcServices($missingServices, $entity, $config, $version);
        }
    }

    /**
     * Determine the base service name for authorization service keys
     * 
     * @param AuthorizationEntity $entity 
     * @return array
     */
    protected function getBaseServiceNamesFromEntity(AuthorizationEntity $entity)
    {
        $services = array_keys($entity->getArrayCopy());
        array_walk($services, function (&$serviceName) {
            $serviceName = preg_replace('/::.*?$/', '', $serviceName);
        });
        return $services;
    }
}
