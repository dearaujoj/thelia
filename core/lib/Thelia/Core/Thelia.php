<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia	                                                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : info@thelia.net                                                      */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/

namespace Thelia\Core;

/**
 * Root class of Thelia
 *
 * It extends Symfony\Component\HttpKernel\Kernel for changing some features
 *
 *
 * @author Manuel Raynaud <mraynaud@openstudio.fr>
 */

use Propel\Runtime\Connection\ConnectionWrapper;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

use Thelia\Core\Bundle;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Config\DatabaseConfiguration;
use Thelia\Config\DefinePropel;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\TheliaContainerBuilder;
use Thelia\Core\DependencyInjection\Loader\XmlFileLoader;
use Thelia\Model\ConfigQuery;
use Symfony\Component\Config\FileLocator;

use Propel\Runtime\Propel;
use Propel\Runtime\Connection\ConnectionManagerSingle;
use Thelia\Core\Template\TemplateHelper;
use Thelia\Log\Tlog;

class Thelia extends Kernel
{

    const THELIA_VERSION = '2.0.0-beta2';

    public function init()
    {
        parent::init();
        if ($this->debug) {
            ini_set('display_errors', 1);
        }
        $this->initPropel();
    }

    protected function initPropel()
    {
        if (file_exists(THELIA_CONF_DIR . 'database.yml') === false) {
            return ;
        }

        $definePropel = new DefinePropel(new DatabaseConfiguration(),
            Yaml::parse(THELIA_CONF_DIR . 'database.yml'));
        $serviceContainer = Propel::getServiceContainer();
        $serviceContainer->setAdapterClass('thelia', 'mysql');
        $manager = new ConnectionManagerSingle();
        $manager->setConfiguration($definePropel->getConfig());
        $serviceContainer->setConnectionManager('thelia', $manager);
        $con = Propel::getConnection(\Thelia\Model\Map\ProductTableMap::DATABASE_NAME);
        $con->setAttribute(ConnectionWrapper::PROPEL_ATTR_CACHE_PREPARES, true);
        if ($this->isDebug()) {
            $serviceContainer->setLogger('defaultLogger', \Thelia\Log\Tlog::getInstance());
            $con->useDebug(true);
        }

    }

    /**
     * dispatch an event when application is boot
     */
    public function boot()
    {
        parent::boot();

        if (file_exists(THELIA_CONF_DIR . 'database.yml') === true) {
            $this->getContainer()->get("event_dispatcher")->dispatch(TheliaEvents::BOOT);
        }

    }

    /**
     * Add all module's standard templates to the parser environment
     *
     * @param TheliaParser $parser the parser
     * @param Module $module the Module.
     */
    protected function addStandardModuleTemplatesToParserEnvironment($parser, $module) {
        $stdTpls = TemplateDefinition::getStandardTemplatesSubdirsIterator();

        foreach($stdTpls as $templateType => $templateSubdirName) {
            $this->addModuleTemplateToParserEnvironment($parser, $module, $templateType, $templateSubdirName);
        }
    }

    /**
     * Add a module template directory to the parser environment
     *
     * @param TheliaParser $parser the parser
     * @param Module $module the Module.
     * @param string $templateType the template type (one of the TemplateDefinition type constants)
     * @param string $templateSubdirName the template subdirectory name (one of the TemplateDefinition::XXX_SUBDIR constants)
     */
    protected function addModuleTemplateToParserEnvironment($parser, $module, $templateType, $templateSubdirName) {

        // Get template path
        $templateDirectory = $module->getAbsoluteTemplateDirectoryPath($templateSubdirName);

        try {
            $templateDirBrowser = new \DirectoryIterator($templateDirectory);

            $code = ucfirst($module->getCode());

            /* browse the directory */
            foreach ($templateDirBrowser as $templateDirContent) {

                /* is it a directory which is not . or .. ? */
                if ($templateDirContent->isDir() && ! $templateDirContent->isDot()) {

                    $parser->addMethodCall(
                        'addTemplateDirectory',
                        array(
                            $templateType,
                            $templateDirContent->getFilename(),
                            $templateDirContent->getPathName(),
                            $code
                        )
                    );
                }
            }
        }
        catch (\UnexpectedValueException $ex) {
            // The directory does not exists, ignore it.
        }
    }

    /**
     *
     * Load some configuration
     * Initialize all plugins
     *
     */
    protected function loadConfiguration(ContainerBuilder $container)
    {

        $loader = new XmlFileLoader($container, new FileLocator(THELIA_ROOT . "/core/lib/Thelia/Config/Resources"));
        $finder = Finder::create()
            ->name('*.xml')
            ->depth(0)
            ->in(THELIA_ROOT . "/core/lib/Thelia/Config/Resources");

        foreach($finder as $file) {
            $loader->load($file->getBaseName());
        }

        if (defined("THELIA_INSTALL_MODE") === false) {
            $modules = \Thelia\Model\ModuleQuery::getActivated();

            $translationDirs = array();
            $parser = $container->getDefinition('thelia.parser');

            foreach ($modules as $module) {

                try {
                    $definition = new Definition();
                    $definition->setClass($module->getFullNamespace());
                    $definition->addMethodCall("setContainer", array(new Reference('service_container')));

                    $container->setDefinition(
                        "module.".$module->getCode(),
                        $definition
                    );

                    $loader = new XmlFileLoader($container, new FileLocator($module->getAbsoluteConfigPath()));
                    $loader->load("config.xml");

                    if (is_dir($dir = $module->getAbsoluteI18nPath())) {
                        $translationDirs[] = $dir;
                    }

                    $this->addStandardModuleTemplatesToParserEnvironment($parser, $module);

                } catch (\InvalidArgumentException $e) {
                    Tlog::getInstance()->addError(sprintf("Failed to load module %s: %s", $module->getCode(), $e->getMessage()), $e);
                }
            }

            // Load translation from templates
            // core translation
            $translationDirs[] = THELIA_ROOT . "core/lib/Thelia/Config/I18n";

            // Standard templates (front, back, pdf, mail)
            $th = TemplateHelper::getInstance();

            foreach($th->getStandardTemplateDefinitions() as $templateDefinition) {
                if (is_dir($dir = $templateDefinition->getAbsoluteI18nPath())) {
                    $translationDirs[] = $dir;
                }
            }

            if ($translationDirs) {
                $this->loadTranslation($container, $translationDirs);
            }
        }
    }

    private function loadTranslation(ContainerBuilder $container, array $dirs)
    {
        $translator = $container->getDefinition('thelia.translator');

        $finder = Finder::create()
            ->files()
            ->depth(0)
            ->in($dirs);

        foreach ($finder as $file) {
            list($locale, $format) = explode('.', $file->getBaseName(), 2);
            $translator->addMethodCall('addResource', array($format, (string) $file, $locale));
        }
    }

    /**
     *
     * initialize session in Request object
     *
     * All param must be change in Config table
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     */

    /**
     * Gets a new ContainerBuilder instance used to build the service container.
     *
     * @return ContainerBuilder
     */
    protected function getContainerBuilder()
    {
        return new TheliaContainerBuilder(new ParameterBag($this->getKernelParameters()));
    }

    /**
     * Builds the service container.
     *
     * @return ContainerBuilder The compiled service container
     *
     * @throws \RuntimeException
     */
    protected function buildContainer()
    {
        $container = parent::buildContainer();

        $this->loadConfiguration($container);
        $container->customCompile();

        return $container;
    }

    /**
     * Gets the cache directory.
     *
     * @return string The cache directory
     *
     * @api
     */
    public function getCacheDir()
    {
        if (defined('THELIA_ROOT')) {
            return THELIA_ROOT.'cache/'.$this->environment;
        } else {
            return parent::getCacheDir();
        }

    }

    /**
     * Gets the log directory.
     *
     * @return string The log directory
     *
     * @api
     */
    public function getLogDir()
    {
        if (defined('THELIA_ROOT')) {
            return THELIA_ROOT.'log/';
        } else {
            return parent::getLogDir();
        }
    }

    /**
     * Returns the kernel parameters.
     *
     * @return array An array of kernel parameters
     */
    protected function getKernelParameters()
    {
        $parameters = parent::getKernelParameters();

        $parameters["thelia.root_dir"] = THELIA_ROOT;
        $parameters["thelia.core_dir"] = THELIA_ROOT . "core/lib/Thelia";
        $parameters["thelia.module_dir"] = THELIA_MODULE_DIR;

        return $parameters;
    }

    /**
     * return available bundle
     *
     * Part of Symfony\Component\HttpKernel\KernelInterface
     *
     * @return array An array of bundle instances.
     *
     */
    public function registerBundles()
    {
        $bundles = array(
            /* TheliaBundle contain all the dependency injection description */
            new Bundle\TheliaBundle(),
        );

        /**
         * OTHER CORE BUNDLE CAN BE DECLARE HERE AND INITIALIZE WITH SPECIFIC CONFIGURATION
         *
         * HOW TO DECLARE OTHER BUNDLE ? ETC
         */

        return $bundles;

    }

    /**
     * Loads the container configuration
     *
     * part of Symfony\Component\HttpKernel\KernelInterface
     *
     * @param LoaderInterface $loader A LoaderInterface instance
     *
     * @api
     */
    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        //Nothing is load here but it's possible to load container configuration here.
        //exemple in sf2 : $loader->load(__DIR__.'/config/config_'.$this->getEnvironment().'.yml');
    }
}
