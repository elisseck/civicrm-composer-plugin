<?php

namespace Roundearth\CivicrmComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Plugin\PluginInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Composer plugin to add support for CiviCRM.
 */
class Plugin implements PluginInterface, EventSubscriberInterface {

  const ASSET_EXTENSIONS = [
    'html',
    'js',
    'css',
    'svg',
    'png',
    'jpg',
    'jpeg',
    'ico',
    'gif',
    'woff',
    'woff2',
    'ttf',
    'eot',
    'swf',
  ];

  /**
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * @var \Symfony\Component\Filesystem\Filesystem
   */
  protected $filesystem;

  /**
   * @var \Roundearth\CivicrmComposerPlugin\Util
   */
  protected $util;

  /**
   * Plugin constructor.
   */
  public function __construct() {
    $this->filesystem = new Filesystem();
    $this->util = new Util($this->filesystem);
  }

  /**
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PackageEvents::POST_PACKAGE_INSTALL => 'onPackageInstallOrUpdate',
      PackageEvents::POST_PACKAGE_UPDATE => 'onPackageInstallOrUpdate',
    ];
  }

  /**
   * Event callback for either the install or update package events.
   *
   * @param \Composer\Installer\PackageEvent $event
   *   The event.
   */
  public function onPackageInstallOrUpdate(PackageEvent $event) {
    /** @var \Composer\DependencyResolver\Operation\InstallOperation|\Composer\DependencyResolver\Operation\UpdateOperation $operation */
    $operation = $event->getOperation();

    $package = method_exists($operation, 'getPackage')
        ? $operation->getPackage()
        : $operation->getInitialPackage();

    $name = $package->getName();

    if ($name == 'civicrm/civicrm-core') {
      $this->afterCivicrmInstallOrUpdate($package);
    }
  }

  /**
   * Gets the path to the CiviCRM code.
   *
   * @return string
   */
  protected function getCivicrmCorePath() {
    $vendor_path = $this->composer->getConfig()->get('vendor-dir');
    return "{$vendor_path}/civicrm/civicrm-core";
  }

  /**
   * Does all the stuff we want to do after CiviCRM has been installed.
   */
  protected function afterCivicrmInstallOrUpdate(Package $civicrm_package) {
    if (preg_match('/(\d+\.\d+\.\d+)/', $civicrm_package->getPrettyVersion(), $matches)) {
      $civicrm_version = $matches[1];
    }
    else {
      // @todo Allow the user to give a version number.
      throw new \RuntimeException("Unable to determine CiviCRM release version from {$civicrm_package->getPrettyVersion()}");
    }

    $this->runBower();
    $this->addMissingCivicrmFiles($civicrm_version);
    $this->downloadCivicrmExtensions();
    $this->syncWebAssetsToWebRoot();
  }

  /**
   * Outputs a message to the user.
   *
   * @param string $message
   *   The message.
   * @param bool $newline
   *   Whether or not to add a newline.
   * @param int $verbosity
   *   The verbosity.
   */
  protected function output($message, $newline = TRUE, $verbosity = IOInterface::NORMAL) {
    $this->io->write("> [civicrm-composer-plugin] {$message}", $newline, $verbosity);
  }

  /**
   * Runs bower in civicrm-core/civicrm.
   */
  protected function runBower() {
    $this->output("<info>Running bower for CiviCRM...</info>");
    $bower = (new Process("bower install", $this->getCivicrmCorePath()))->mustRun();
    $this->output($bower->getOutput(), FALSE, IOInterface::VERBOSE);
  }

  /**
   * Adds all the missing files from the release tarball.
   *
   * @param string $civicrm_version
   *   The CiviCRM version.
   */
  protected function addMissingCivicrmFiles($civicrm_version) {
    $civicrm_core_path = $this->getCivicrmCorePath();
    $civicrm_archive_url = "https://download.civicrm.org/civicrm-{$civicrm_version}-drupal.tar.gz";
    $civicrm_archive_file = tempnam(sys_get_temp_dir(), "drupal-civicrm-archive-");
    $civicrm_extract_path = tempnam(sys_get_temp_dir(), "drupal-civicrm-extract-");

    // Convert the extract path into a directory.
    $this->filesystem->remove($civicrm_extract_path);
    $this->filesystem->mkdir($civicrm_extract_path);

    try {
      $this->output("<info>Downloading CiviCRM {$civicrm_version} release...</info>");
      file_put_contents($civicrm_archive_file, fopen($civicrm_archive_url, 'r'));

      $this->output("<info>Extracting CiviCRM {$civicrm_version} release...</info>");
      (new \Archive_Tar($civicrm_archive_file, "gz"))->extract($civicrm_extract_path);

      $this->output("<info>Copying missing files from CiviCRM release...</info>");

      $this->filesystem->mirror("{$civicrm_extract_path}/civicrm/packages", "{$civicrm_core_path}/packages");
      $this->filesystem->mirror("{$civicrm_extract_path}/civicrm/sql", "{$civicrm_core_path}/sql");

      file_put_contents("{$civicrm_core_path}/civicrm-version.php", str_replace('Drupal', 'Drupal8', file_get_contents("{$civicrm_extract_path}/civicrm/civicrm-version.php")));

      $simple_copy_list = [
        'civicrm.config.php',
        'CRM/Core/I18n/SchemaStructure.php',
        'install/langs.php',
      ];
      foreach ($simple_copy_list as $file) {
        $this->filesystem->copy("{$civicrm_extract_path}/civicrm/{$file}", "{$civicrm_core_path}/{$file}");
      }
    }
    finally {
      if (file_exists($civicrm_archive_file)) {
        unlink($civicrm_archive_file);
      }

      if (file_exists($civicrm_extract_path)) {
        $this->util->removeDirectoryRecursively($civicrm_extract_path);
      }
    }
  }

  /**
   * Download CiviCRM extensions based on configuration in 'extra'.
   */
  protected function downloadCivicrmExtensions() {
    /** @var \Composer\Package\RootPackageInterface $package */
    $package = $this->composer->getPackage();
    $extra = $package->getExtra();

    if (!empty($extra['civicrm']['extensions'])) {
      foreach ($extra['civicrm']['extensions'] as $name => $url) {
        $this->downloadCivicrmExtension($name, $url);
      }
    }
  }

  /**
   * Download a single CiviCRM extension.
   *
   * @param string $name
   *   The extension name.
   * @param string $url
   *   The URL to the zip archive.
   */
  protected function downloadCivicrmExtension($name, $url) {
    $extension_archive_file = tempnam(sys_get_temp_dir(), "drupal-civicrm-extension-");
    $this->output("<info>Downloading CiviCRM extension {$name} from {$url}...</info>");
    file_put_contents($extension_archive_file, fopen($url, 'r'));

    $extension_path = $this->getCivicrmCorePath() . '/tools/extensions';
    $firstFile = NULL;

    try {
      $zip = new \ZipArchive();
      $zip->open($extension_archive_file);
      $firstFile = $zip->getNameIndex(0);
      $zip->extractTo($extension_path);
      $zip->close();
    }
    finally {
      $this->filesystem->remove($extension_archive_file);
    }

    // Attempt to rename directory to extension name.
    $parts = explode('/', $firstFile);
    if (count($parts) > 1) {
      $this->filesystem->rename("{$extension_path}/{$parts[0]}", "{$extension_path}/{$name}");
    }
  }

  /**
   * Syncs web assets from CiviCRM to the web root.
   */
  protected function syncWebAssetsToWebRoot() {
    $source = $this->getCivicrmCorePath();
    $destination = './web/libraries/civicrm';
    $this->output("<info>Syncing CiviCRM web assets to /web/libraries/civicrm...</info>");

    $this->util->removeDirectoryRecursively($destination);

    $this->util->mirrorFilesWithExtensions($source, $destination, static::ASSET_EXTENSIONS);

    $this->util->removeDirectoryRecursively("{$destination}/tests");

    $this->filesystem->mirror("{$source}/extern", "{$destination}/extern");
    $this->filesystem->copy("{$source}/civicrm.config.php", "{$destination}/civicrm.config.php");

    $settings_location_php = <<<EOF
<?php

define('CIVICRM_CONFDIR', '../../../sites');
EOF;
    file_put_contents("{$destination}/settings_location.php", $settings_location_php);
  }

}