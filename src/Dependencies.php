<?php
/**
 * Dependencies of hordectl
 */
namespace Horde\Hordectl;
use \Horde\Hordectl\Configuration\AppConfigReader;
use Horde\Injector\Injector;

class Dependencies extends Injector
{
    protected $hordeBootstrapped = false;

    public function __construct($scope)
    {
        parent::__construct($scope);
        $this->bootstrapHorde();
        $this->setupCommonDependencies();
    }

    /**
     * Return the path to horde
     * 
     */
    public function findHordePath()
    {
        $env = new Environment(getenv());
        $finder = new HordeInstallationFinder($env);
        return $finder->find();
    }

    /**
     * Perform bootstrap of the horde base app
     *
     * @throws HordeBootstrapException if bootstrap fails
     */
    public function bootstrapHorde()
    {
        if (!$this->hordeBootstrapped) {
            try {
                require_once $this->findHordePath() . '/lib/Application.php';
                $app = \Horde_Registry::appInit('horde', ['cli' => true]);
                $this->setInstance('HordeApplication', $app);
                $this->setInstance('HordeInjector', $GLOBALS['injector']);
                $this->setInstance('HordeConfig', $GLOBALS['conf']);
                $this->setInstance('HordeRegistry', $GLOBALS['registry']);
                $this->setInstance('HordePrefs', $GLOBALS['prefs']);
            } catch (\Throwable $e) {
                throw new HordeBootstrapException(
                    'Horde bootstrap failed: ' . $e->getMessage(),
                    $e
                );
            }
        }
        $this->hordeBootstrapped = true;
    }

    /**
     * Setup some dependencies which are too simple/common for a full factory
     */
    public function setupCommonDependencies()
    {
        // Yes, this is really necessary for some factories. Sort this out
        global $conf;
        $hordeInjector = $this->getInstance('HordeInjector');
        $hordeGroup = $hordeInjector->getInstance('Horde_Group');
        $hordePerms = $hordeInjector->getInstance('Horde_Perms');
//        $hordePrefs = $hordeInjector->getInstance('\Horde\Hordectl\Compat\Horde_Core_Factory_Prefs');
//        $hordeInjector->setInstance('Horde_Core_Factory_Prefs', $hordePrefs);
//        $this->setInstance('Horde_Core_Factory_Prefs', $hordePrefs);
        $hordeAuth = $hordeInjector->getInstance('Horde_Core_Factory_Auth')->create();
        $this->setInstance('\Horde_Auth_Base', $hordeAuth);
        $this->setInstance('AppConfigReader', $this->getInstance('\Horde\Hordectl\Configuration\AppConfigReader'));

        $hordeIdentity = $hordeInjector->getInstance('Horde_Core_Factory_Identity');
/*        $this->setInstance('Horde_Core_Factory_Identity', $hordeIdentity);
        $hordeInjector->setInstance('Horde_Core_Factory_Identity', $hordeIdentity);*/
        $hordeCorePerms = $hordeInjector->getInstance('Horde_Core_Perms');

        $this->setInstance('GroupRepo',
            new Repository\Group($hordeGroup)
        );
        $this->setInstance('UserRepo',
            new Repository\User($hordeAuth, $hordeIdentity)
        );
        $this->setInstance('PermsRepo',
            new Repository\Permission(
                $hordePerms, 
                $hordeCorePerms, 
                $this->getInstance('GroupRepo')
            )
        );
        return $this;
    }
    /**
     * Expose Horde Config in global namespace
     *
     * @return Dependencies
     */
    public function globalizeHordeConfig()
    {
        global $conf;
        $GLOBALS['conf'] = $this->getInstance('HordeConfig');
        return $this;
    }

    /**
     * Push/initialize all globals which may be used by application code
     * 
     * Use this in import/app or query/app contexts
     */
    public function globalizeApp()
    {
        $this->globalizeHordeConfig();

    }

    /**
     * Hide Horde Config from global namespace
     * 
     * Most likely this will not cover all edge cases
     * 
     * @return Dependencies
     */
    public function unglobalizeHordeConfig()
    {
        unset($GLOBALS['conf']);
        return $this;
    }

    /**
     * Return a list of applications from the Horde Registry
     * 
     * If we have not connected to a working registry, the list will be empty
     * 
     * @return string[]
     */
    public function getRegistryApplications(): array
    {
        $registry = $this->getInstance('HordeRegistry');
        return $registry->listAllApps();
    }

    /**
     * Return the application resource provider
     * 
     * @return object
     */
    public function getApplicationResources(string $app): ?object
    {
        $registry = $this->getInstance('HordeRegistry');
        $hordeInjector = $this->getInstance('HordeInjector');
        /**
         * Check if registry has that application
         */
        if (empty($registry->applications[$app])) {
            return null;
        }
        /**
         * Inactive apps don't provide resources
         */
        if ($registry->applications[$app]['status'] === 'inactive') {
            return null;
        }
        /**
         * Setup basic autoloading for this app
         */
         // Get the Horde or Composer Autoloader
        $hordeAutoloader = null;
        $composerAutoloader = null;
        foreach (spl_autoload_functions() as $id => $loader) {
            // TODO: Make this more robust
            if (get_class($loader[0]) == 'Composer\Autoload\ClassLoader') {
                $composerAutoloader = $loader[0];
            }
            if (get_class($loader[0]) == 'Horde_Autoloader_Default') {
                $hordeAutoloader = $loader[0];
            }
        }
        // Set application dirs
        $libdir = realpath($registry->get('fileroot', $app)) . '/lib/';
        $srcdir = realpath($registry->get('fileroot', $app)) . '/src/';
        if ($composerAutoloader) {
            // This seems to do nothing
            $composerAutoloader->add(ucfirst($app), $libdir);
            // PSR-4 Old-Style
            $composerAutoloader->addPsr4('Horde\\' . ucfirst($app) . '\\', $libdir);
            // PSR-4 in src dir
            $composerAutoloader->addPsr4('Horde\\' . ucfirst($app) . '\\', $srcdir);
            $composerAutoloader->register();
        }

        if ($hordeAutoloader) {
            $hordeAutoloader->addClassPathMapper(
            new \Horde_Autoloader_ClassPathMapper_PrefixString(
                $app, $libdir
            ));
        }

        \Horde_Registry::appInit($app, ['cli' => true]);
        $registry->pushApp($app, ['check_perms' => false]);

        /**
         * Check if the application provides a 
         * \Horde\$App\ApplicationResources class
         * TODO: Maybe check the registry for file root and deduce a location
         * For now, rely on the autoloader
         */
        $classname = '\Horde\\' . ucfirst($app) . '\ApplicationResources';

        if (!class_exists($classname)) {
            return null;
        }
        $appResources = $hordeInjector->getInstance($classname);
        return $appResources;
    }
}
