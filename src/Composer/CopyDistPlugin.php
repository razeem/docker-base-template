<?php

declare(strict_types=1);

namespace Razeem\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * This class implements a Composer plugin that copies docker configuration files to the project root.
 * @psalm-suppress MissingConstructor
 */
class CopyDistPlugin implements PluginInterface, EventSubscriberInterface {

  /**
   * @var Composer\IO\IOInterface
   */
  private $io;

  /**
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io) {
    $this->io = $io;
  }

  /**
   * {@inheritdoc}
   */
  public function deactivate(Composer $composer, IOInterface $io): void {}

  /**
   * {@inheritdoc}
   */
  public function uninstall(Composer $composer, IOInterface $io): void {}

  public static function getSubscribedEvents() {
    return [
      ScriptEvents::POST_INSTALL_CMD => ['copyDist', 10],
      ScriptEvents::POST_UPDATE_CMD => ['copyDist', 10],
    ];
  }

  /**
   * Copies the dist folder from the plugin to the main project root.
   * If a project-code.txt file exists in the target or project root, its contents
   * are used to replace all occurrences of 'project_name' in docker-compose.yml files.
   *
   * @param \Composer\Script\Event $event
   *   The Composer event object.
   */
  public function copyDist(Event $event) {
    $sourceDir = __DIR__ . '/../../dist';
    $targetDir = getcwd();

    if (!is_dir($sourceDir)) {
      $event->getIO()->writeError('<error>No dist folder found in plugin.</error>');
      return;
    }

    // Read project code from project-code.txt
    $projectCodeFile = $targetDir . '/project-details.yml';
    try {
      $project_content = file_exists($projectCodeFile)
        ? file_get_contents($projectCodeFile)
        : '';
      $project_details = Yaml::parse($project_content);
    }
    catch (ParseException $e) {
      // Log the YAML parsing error using the injected logger service.
      echo "YAML parsing error: \n" . $e->getMessage();
      // Return an empty array in case of parsing error.
    }
    $projectFolder = strtolower(
      $project_details['projectcode']
      ? trim($project_details['projectcode'])
      : substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 3)
    );
    $projectCode = $projectFolder . '_docker_local';

    $this->recurseCopyWithReplace($sourceDir, $targetDir, $projectCode, $projectFolder);

    // Multisite support: copy settings and .env for each site in multisites.list
    $multisitesFile = $project_details['multisite']
      ? explode(' ', $project_details['multisite'])
      : [];
    $settingsTemplate = $targetDir . '/Docker/app/settings-multisite.php';
    $envTemplate = file_exists($targetDir . '/.env.dist')
      ? $targetDir . '/.env.dist'
      : $sourceDir . '/Docker/.env.dist';
    $dockerSettingsDir = $targetDir . '/Docker/settings';
    $envContentDb = [
      'DRUPAL_MULTISITE_DB_NAME = drupal_docker',
      'DRUPAL_MULTISITE_DB_USERNAME = drupal_docker',
      'DRUPAL_MULTISITE_DB_PASSWORD = drupal_docker',
    ];
    if (!empty($multisitesFile)) {
      @mkdir($dockerSettingsDir, 0777, TRUE);
      $envReplaceContent = '';
      foreach ($multisitesFile as $sitename) {
        // $siteDir = $webRoot . '/' . $sitename;
        // Copy settings-multi.php as settings.php
        if (file_exists($settingsTemplate)) {
          copy($settingsTemplate, $dockerSettingsDir . '/settings.' . $sitename . '.php');
          $settingsContent = file_get_contents($dockerSettingsDir . '/settings.' . $sitename . '.php');
          $settingsContent = str_replace('DRUPAL_MULTISITE_', 'DRUPAL_' . strtoupper($sitename) . '_', $settingsContent);
          file_put_contents($dockerSettingsDir . '/settings.' . $sitename . '.php', $settingsContent);
        }
        // Create .env for each multisite
        if (file_exists($envTemplate)) {
          foreach ($envContentDb as $envContentDbValue) {
            $envReplaceContent .= str_replace('DRUPAL_MULTISITE_', 'DRUPAL_' . strtoupper($sitename) . '_', $envContentDbValue) . "\n";
          }
        }
      }
      $envContentData = file_get_contents($envTemplate);
      $envContentData = $envContentData . trim($envReplaceContent);
      file_put_contents($dockerSettingsDir . '/test.env', $envContentData);
    }

    $event->getIO()->write('<info>dist folder copied to project root.</info>');
  }

  /**
   * Recursively copies files and directories from source to destination.
   * If a docker-compose.yml file is found, replaces 'project_name' with the given project code.
   *
   * @param string $src
   *   Source directory path.
   * @param string $dst
   *   Destination directory path.
   * @param string $projectCode
   *   The project code to replace 'project_name' with in docker-compose files.
   * @param string $projectFolder
   *   The project code to replace 'project_folder' with in docker-compose files.
   */
  private function recurseCopyWithReplace($src, $dst, $projectCode, $projectFolder) {
    $dir = opendir($src);
    @mkdir($dst, 0777, TRUE);
    while (FALSE !== ($file = readdir($dir))) {
      if (($file != '.') && ($file != '..')) {
        $srcPath = $src . '/' . $file;
        $dstPath = $dst . '/' . $file;
        if (is_dir($srcPath)) {
          $this->recurseCopyWithReplace($srcPath, $dstPath, $projectCode, $projectFolder);
        }
        else {
          // If docker-compose.yml or docker-compose-vm.yml, replace project_name with project code
          if ($file === 'docker-compose.yml' || $file === 'docker-compose-vm.yml') {
            $content = file_get_contents($srcPath);
            $content = str_replace('project_name', $projectCode, $content);
            $content = str_replace('project_folder', $projectFolder, $content);
            file_put_contents($dstPath, $content);
          }
          // If .env.dist file, rename to .env and place in root destination
          elseif ($file === '.env.dist') {
            $envTargetPath = $dst . '/../.env';
            // Only copy if .env does not already exist in the target directory
            if (!file_exists($envTargetPath)) {
              // Replace project_name with project code in .env.dist before copying
              $content = file_get_contents($srcPath);
              $content = str_replace('project_name', $projectCode, $content);
              file_put_contents($envTargetPath, $content);
            }
          }
          else {
            copy($srcPath, $dstPath);
          }
        }
      }
    }
    closedir($dir);
  }

}
