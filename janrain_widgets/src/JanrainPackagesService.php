<?php

namespace Drupal\janrain_widgets;

use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Cache\DatabaseBackend;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Database\Query\Condition;
use Drupal\file\FileUsage\FileUsageInterface;

/**
 * Class JanrainPackagesService.
 */
class JanrainPackagesService {

  /**
   * Drupal\Core\Database\Driver\mysql\Connection definition.
   *
   * @var \Drupal\Core\Database\Driver\mysql\Connection
   */
  protected $database;

  /**
   * Drupal\Core\Cache\DatabaseBackend definition.
   *
   * @var \Drupal\Core\Cache\DatabaseBackend
   */
  protected $cacheDefault;

  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */
  protected $loggerFactory;

  /**
   * Drupal\Core\File\FileSystem definition.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * Drupal\file\FileUsage\FileUsageInterface definition.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsage;

  /**
   * Constructs a new JanrainPackagesService object.
   *
   * @param \Drupal\Core\Database\Driver\mysql\Connection $database
   *   Database connection service.
   * @param \Drupal\Core\Cache\DatabaseBackend $cache_default
   *   Cache service.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $logger_factory
   *   Logger service.
   * @param \Drupal\Core\File\FileSystem $file_system
   *   File system service.
   * @param \Drupal\file\FileUsage\FileUsageInterface $file_usage
   *   File usage service.
   */
  public function __construct(Connection $database, DatabaseBackend $cache_default, LoggerChannelFactory $logger_factory, FileSystem $file_system, FileUsageInterface $file_usage) {
    $this->database = $database;
    $this->cacheDefault = $cache_default;
    $this->loggerFactory = $logger_factory->get('janrain_widgets');
    $this->fileSystem = $file_system;
    $this->fileUsage = $file_usage;
  }

  /**
   * Helper to remove package assets by managed file id.
   *
   * Accepts managed file id. Does a sanity check. Purges the unzipped folder then
   * deletes the managed file.
   *
   * @param int $fid
   *   File id.
   */
  public function removePkg($fid) {
    $file = File::load($fid);

    // Sanity check that you're only trying to remove files from janrain_widgets.
    if (0 !== strpos($file->getFileUri(), 'public://janrain_widgets/')) {
      $this->loggerFactory->error('Invalid remove location for {{pkg}}!', ['{{pkg}}' => $file->getFilename()]);
      return FALSE;
    }

    // Remove.
    $folder = sprintf('public://janrain_widgets/%s', basename($file->getFilename(), '.zip'));
    if (file_unmanaged_delete_recursive($folder)) {
      $this->loggerFactory->notice('Removed package {{file}}', ['{{file}}' => $file->getFilename()]);
    }
    $success = $file->delete();
    if ($success) {
      $this->loggerFactory->notice('Removed file {{uri}}', ['{{uri}}' => $file->getFileUri()]);
      // Destroy all blocks created from this widget package.
      $delete = $this->database->delete('block');
      $delete_block_roles = $this->database->delete('block_role');
      $delete_where = new Condition('AND');
      $delete_where->condition('module', 'janrain_widgets');
      $delete_where->condition('delta', "%_$fid", 'LIKE');
      $delete_block_roles->condition($delete_where)->execute();
      $num = $delete->condition($delete_where)->execute();
      $this->loggerFactory->notice('Removed {{num}} blocks for {{file}}', [
        '{{file}}' => $file->getFilename(),
        '{{num}}' => $num,
      ]);
    }
    return $success;
  }

  /**
   * Helper to install static assets from managed upload.
   *
   * Does a quick sanity check, then purges existing installation and "unzips"
   * the package into it's own folder. Note: using phar because ZipArchive is not
   * always present.
   *
   * @param \Drupal\file\FileInterface $file
   *   The File entity.
   */
  public function installPkg(FileInterface $file) {
    // Sanity check that file is a file and is a widget package.
    if (0 !== strpos($file->getFileUri(), 'public://janrain_widgets/')) {
      $this->loggerFactory->error('Invalid install location for {{pkg}}!', ['{{pkg}}' => $file->getFilename()]);
      return FALSE;
    }

    // Prepare install folder.
    $install_path = sprintf('public://janrain_widgets/%s', basename($file->getFileUri(), '.zip'));
    file_unmanaged_delete_recursive($install_path);
    file_prepare_directory($install_path, FILE_CREATE_DIRECTORY);
    // Extract package.
    $phar = new \PharData($this->fileSystem->realpath($file->getFileUri()), \FilesystemIterator::KEY_AS_FILENAME);
    foreach ($phar as $name => $info) {
      $fp = fopen($info, 'r');
      $success = file_unmanaged_save_data($fp, "{$install_path}/{$name}", FILE_EXISTS_REPLACE);
      fclose($fp);
      if (!$success) {
        $this->loggerFactory->error('Package install failed!');
        return FALSE;
      }
    }
    $this->loggerFactory->notice('Installed {{pkg}} successfully!', ['{{pkg}}' => $file->getFilename()]);
    return TRUE;
  }

  /**
   * Helper to actually save a managed file upload.
   *
   * Basically working around invisble managed file versioning and "default-temp"
   * assumptions. Note: we add a file_usage as a requirement of managed files. We
   * always force delete which removes the usage.
   *
   * @param int $fid
   *   File id.
   */
  public function savePkg($fid) {
    // Get file.
    $file = File::load($fid);

    // File exists, is zip, and lives where it should. Proceed.
    // Deal with managed file quirks.
    $file->set('status', FILE_STATUS_PERMANENT);
    $file->setFilename(basename($file->getFileUri()));
    $this->fileUsage->add($file, 'janrain_widgets', 'package', $file->id());
    return $file->save();
  }

  /**
   * Helper to manage cached list of available widget packages.
   *
   * Returns array[package_uri] => file_id. Caches results so cache clearing is
   * necessary to update widgets list.
   */
  public function listPkgs() {
    // Shortcut return cache.
    $pkgs = $this->cacheDefault->get('janrain_widgets:packages');
    if ($pkgs) {
      return $pkgs->data;
    }

    // Cache empty fill cache.
    $out = [];
    $result = $this->database->select('file_managed', 'fm')
      ->fields('fm', ['fid', 'uri'])
      ->condition('uri', 'public://janrain_widgets/%', 'LIKE')
      ->condition('status', '1')
      ->execute();
    while ($file = $result->fetchObject()) {
      $out[$file->uri] = (int) $file->fid;
    }
    $this->cacheDefault->set('janrain_widgets:packages', $out);
    return $out;
  }

  /**
   * Helper to synchronize upload folder to managed files.
   *
   * There may be a situation during upgrades and or uninstall/reinstall cycles
   * where drupal forgets about some packages. This function helps ensure packages
   * wont be lost, however, if they become unbound from their managed file entry
   * they'll be assigned a new managed file (new blocks). Likewise, managed files
   * that point to nothing are purged. This clears the list cache.
   */
  public function discoverPkgs() {
    // Track success of all ops.
    $success = TRUE;
    // Purge temporary uploads.
    $temp_managed_files = $this->database->select('file_managed', 'fm')
      ->fields('fm')
      ->condition('uri', 'public://janrain_widgets/%', 'LIKE')
      ->condition('status', '0')
      ->execute();
    foreach ($temp_managed_files as $f) {
      $success = $this->removePkg($f->fid) && $success;
    }

    // Lookup pkgs in folder.
    $pkgs = file_scan_directory('public://janrain_widgets', '/.*\.zip$/');
    $pkg_uris = array_keys($pkgs);

    // Find managed file ids.
    $manageds = $this->database->select('file_managed', 'fm')
      ->fields('fm')
      ->condition('uri', 'public://janrain_widgets/%', 'LIKE')
      ->condition('status', '1')
      ->execute()->fetchAllAssoc('uri');
    $managed_uris = array_keys($manageds);

    // First add packages Drupal doesn't know about.
    foreach (array_diff($pkg_uris, $managed_uris) as $new_pkg) {
      $new_file = file_save_data(file_get_contents($new_pkg), $new_pkg, FILE_EXISTS_REPLACE);
      if ($new_file && $this->fileUsage->add($new_file, 'janrain_widgets', 'package', $new_file->id())) {
        $success = $this->installPkg($new_file) && $success;
      }
    }

    // Next remove packages Drupal is incorrectly pointing to.
    foreach (array_diff($managed_uris, $pkg_uris) as $missing_pkg) {
      $file = File::load($manageds[$missing_pkg]->fid);
      if ($file) {
        $success = $this->removePkg($file->id()) && $success;
      }
    }

    // Flush package cache.
    $this->cacheDefault->invalidate('janrain_widgets:packages');
    return $success;
  }

}
