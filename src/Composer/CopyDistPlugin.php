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
 * This class implements a Composer plugin that copies Docker configuration files to the project root.
 * It supports project code and multisite setups via project-details.yml.
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
   * If project-details.yml exists, its contents are used for project code and multisite configuration.
   * If not, prompts the user for project code and creates a default project-details.yml.
   * Replaces 'project_name' and 'project_folder' in docker-compose files.
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

    // Read project code from project-details.yml
    $projectCodeFile = $targetDir . '/project-details.yml';
    try {
      if (file_exists($projectCodeFile)) {
        $project_content = file_get_contents($projectCodeFile);
      }
      else {
        // Prompt user for project name if file does not exist
        $projectName = trim($event->getIO()->ask('<question>Enter your project code (JIRA project ID):</question>'));
        $project_content = "projectcode:\n  $projectName\nmultisite:\n  - default\n";
        $event->getIO()->write("\033[33m<comment>If you have a multisite setup, please update the project-details.yml file by adding the site names under the 'multisite' section. After that, run the composer require command in the terminal again.</comment>\033[0m");
        file_put_contents($projectCodeFile, $project_content);
      }
      $project_details = Yaml::parse($project_content);
    }
    catch (ParseException $e) {
      // Log the YAML parsing error using the injected logger service.
      echo "YAML parsing error: \n" . $e->getMessage();
      // Return an empty array in case of parsing error.
    }
    $projectFolder = strtolower(trim($project_details['projectcode']));
    $projectCode = $projectFolder . '_docker_local';
    $multisitesFile = $project_details['multisite'];
    $this->recurseCopyWithReplace($sourceDir, $targetDir, $projectCode, $projectFolder, $multisitesFile);

    $event->getIO()->write('<info>dist folder copied to project root.</info>');
  }

  /**
   * Recursively copies files and directories from source to destination.
   * If a docker-compose.yml or docker-compose-vm.yml file is found, replaces 'project_name' and 'project_folder'.
   * If a settings.php file is found, generates site files for each multisite.
   *
   * @param string $src
   *   Source directory path.
   * @param string $dst
   *   Destination directory path.
   * @param string $projectCode
   *   The project code to replace 'project_name' with in docker-compose files.
   * @param string $projectFolder
   *   The project code to replace 'project_folder' with in docker-compose files.
   * @param array $multisitesFile
   *   List of multisite names from project-details.yml.
   */
  private function recurseCopyWithReplace($src, $dst, $projectCode, $projectFolder, $multisitesFile) {
    $dir = opendir($src);
    @mkdir($dst, 0777, TRUE);
    while (FALSE !== ($file = readdir($dir))) {
      if (($file != '.') && ($file != '..')) {
        $srcPath = $src . '/' . $file;
        $dstPath = $dst . '/' . $file;
        if (is_dir($srcPath)) {
          $this->recurseCopyWithReplace($srcPath, $dstPath, $projectCode, $projectFolder, $multisitesFile);
        }
        else {
          // If docker-compose.yml or docker-compose-vm.yml, replace project_name with project code
          if ($file === 'docker-compose.yml' || $file === 'docker-compose-vm.yml') {
            $content = file_get_contents($srcPath);
            $content = str_replace('project_name', $projectCode, $content);
            $content = str_replace('project_folder', $projectFolder, $content);
            file_put_contents($dstPath, $content);
          }
          elseif ($file === 'settings.php') {
            $sourceDir = __DIR__ . '/../../dist';
            $targetDir = getcwd();
            $this->generateSiteFiles($sourceDir, $targetDir, $srcPath, $projectCode, $multisitesFile);
          }
          else {
            copy($srcPath, $dstPath);
          }
        }
      }
    }
    closedir($dir);
  }

  /**
   * Generates site files and .env files for each multisite based on the provided configuration.
   *
   * @param string $src
   *   Source directory path.
   * @param string $dst
   *   Destination directory path.
   * @param string $settingsTemplate
   *   Path to the settings.php template file.
   * @param string $projectCode
   *   The project code to use for generating site files.
   * @param array $multisitesFile
   *   List of multisite names from project-details.yml.
   */
  public function generateSiteFiles($src, $dst, $settingsTemplate, $projectCode, $multisitesFile) {
    $webRoot = $dst . '/web';
    $envTemplate = file_exists($src . '/Docker/.env.dist')
      ? $src . '/Docker/.env.dist'
      : $dst . '/.env.dist';
    $envPath = file_exists($dst . '/.env')
      ? $dst . '/.env.dist'
      : $dst . '/.env';
    $envContentDbTemplate = "# {site_name}\n" .
      "DRUPAL_{site_name_upper}_DB_NAME=drupal_docker_{site_name_lower}\n" .
      "DRUPAL_{site_name_upper}_DB_USERNAME=drupal_docker_{site_name_lower}\n" .
      "DRUPAL_{site_name_upper}_DB_PASSWORD=drupal_docker_{site_name_lower}\n" .
      "# {site_name}_redis\n" .
      "DRUPAL_{site_name_upper}_REDIS_HOST=\n" .
      "DRUPAL_{site_name_upper}_REDIS_PORT=\n" .
      "DRUPAL_{site_name_upper}_REDIS_PASSWORD=\n";

    if (!empty($multisitesFile)) {
      $envReplaceContent = '';
      foreach ($multisitesFile as $sitename) {
        // Check if the settings template exists
        if (file_exists($settingsTemplate)) {
          $sitesPath = $webRoot . '/sites/' . $sitename;
          $settingsPath = $sitesPath . '/settings.php';
          $exampleSettingsPath = $sitesPath . '/example.settings.php';
          // Ensure the directory exists
          if (is_dir($sitesPath)) {
            $settingsCheck = file_exists($settingsPath)
              ? $exampleSettingsPath
              : $settingsPath;
            $settingsContent = file_get_contents($settingsTemplate);
            $settingsContent = str_replace('DRUPAL_MULTISITE_', 'DRUPAL_' . strtoupper($sitename) . '_', $settingsContent);
            file_put_contents($settingsCheck, $settingsContent);
          }
          else {
            // Handle the case where the directory does not exist
            throw new \Exception("Directory does not exist: $sitesPath");
          }
        }

        // Create .env for each multisite
        if (file_exists($envTemplate)) {
          // Prepare replacements
          $replacements = [
            '{site_name}' => $sitename,
            '{site_name_upper}' => strtoupper($sitename),
            '{site_name_lower}' => strtolower($sitename),
          ];
          // Replace placeholders in one go
          $envContentDb = str_replace(array_keys($replacements), array_values($replacements), $envContentDbTemplate);
          // Trim each line to avoid extra spaces and tabs
          $envReplaceContent .= $envContentDb . "\n";
        }
      }

      // Remove any trailing newlines from the final content
      $envContentData = file_get_contents($envTemplate) . trim($envReplaceContent);
      $envContentData = str_replace('project_name', $projectCode, $envContentData);
      file_put_contents($envPath, $envContentData);
    }
  }

}
