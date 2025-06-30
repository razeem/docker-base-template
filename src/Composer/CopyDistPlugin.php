<?php

declare(strict_types=1);

namespace Razeem\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

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
    $projectCodeFile = $targetDir . '/project-code.txt';
    $projectFolder = strtolower(
      file_exists($projectCodeFile)
        ? trim(file_get_contents($projectCodeFile))
        : substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 3)
    );
    $projectCode = $projectFolder . '_docker_local';

    $this->recurseCopyWithReplace($sourceDir, $targetDir, $projectCode, $projectFolder);

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
