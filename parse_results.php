<?php

require_once 'vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

/**
 * Drupal.org project url pattern.
 */
const DRUPAL_PROJECT_URL = 'https://www.drupal.org/project';

$config = Yaml::parse(file_get_contents(__DIR__ . '/config.yml'));

$loader = new Twig_Loader_Filesystem('templates');
$twig = new Twig_Environment($loader);

// First, move simpletest results into the correct project.
foreach (new DirectoryIterator('www/simpletest') as $simpletest_file_info) {
  if ($simpletest_file_info->isFile() && preg_match('Drupal_(.+)_Tests', $simpletest_file_info->getFilename(), $matches)) {
    $name = $matches[1];

    // Find the right project.
    do {
      // Check if a project with that name exists.
      if (is_dir('www/' . $name . '/simpletest')) {
        // Move the file, continue with the next.
        rename($simpletest_file_info->getPathname(), 'www/' . $name . '/simpletest/' . $simpletest_file_info->getFilename());
        continue 2;
      }
      // If the name has no _ left, continue with the next file, display an
      // error.
      if (strpos($name, '_') === FALSE) {
        print "ERROR: Failed to move simpletest to the correct project: " . $simpletest_file_info->getFilename() . "\n";
      }
    } while ($name = substr($name, 0, strrpos($name, '_')));
  }
}
rmdir('www/simpletest');

$projects = parse_results('www');
if (is_dir('www-old')) {
  $projects_old = parse_results('www-old');
}

foreach ($projects as $name => &$project) {
  if (!isset($projects_old[$name])) {
    $project['new'] = TRUE;
    continue;
  }

  $project['url'] = get_project_url($name, $config['projects']);

  foreach (['phpunit', 'simpletest'] as $framework) {
    if (!isset($project[$framework])) {
      continue;
    }
    foreach ($project[$framework] as $type => $count) {
      if (isset($projects_old[$name][$framework][$type])) {
        $project[$framework][$type . '_diff'] = $count - $projects_old[$name][$framework][$type];
      }
    }
  }
}

ksort($projects);

$index = $twig->render('index.html.twig', ['projects' => $projects, 'timestamp' => date('Y-m-d H:i:s T')]);
file_put_contents('www/index.html', $index);
file_put_contents('www/projects.json', json_encode($projects, JSON_PRETTY_PRINT));

/**
 * @param $path
 *
 * @return array
 */
function parse_results($path) {
  $projects = array();
  foreach (new DirectoryIterator($path) as $file_info) {
    if ($file_info->isDir() && !$file_info->isDot()) {
      $project = [
        'name' => $file_info->getFilename(),
        'phpunit' => [],
        'simpletest' => [],
      ];
      $phpunit_file = $file_info->getPathName() . '/phpunit.xml';
      if (file_exists($phpunit_file) && filesize($phpunit_file) > 0) {
        $phpunit = simplexml_load_file($phpunit_file);
        $project['phpunit']['assertions'] = (int) $phpunit->testsuite['assertions'];
        $project['phpunit']['failures'] = (int) $phpunit->testsuite['failures'];
        $project['phpunit']['errors'] = (int) $phpunit->testsuite['errrors'];
      }
      $project['simpletest'] = get_simpletest_results($file_info->getPathname());
      $projects[$project['name']] = $project;
    }
  }

  return $projects;
}

/**
 * Returns simpletest test results.
 *
 * @param $simpletest_dir
 *
 * @return array
 */
function get_simpletest_results($project_dir) {
  $results = [
    'assertions' => 0,
    'failures' => 0,
    'errors' => 0,
  ];
  $simpletest_dir = $project_dir . '/simpletest';
  if (is_dir($simpletest_dir)) {
    foreach (new DirectoryIterator($simpletest_dir) as $simpletest_file_info) {
      if ($simpletest_file_info->isFile()) {
        $simpletest_xml = simplexml_load_file($simpletest_file_info->getRealPath());
        $results['assertions'] += count($simpletest_xml->testcase);
        $results['failures'] += count($simpletest_xml->xpath('/testsuite/testcase/failure'));
        $results['errors'] += count($simpletest_xml->xpath('/testsuite/testcase/error'));
      }
    }
  }
  return $results;
}

/**
 * Get a project url.
 *
 * @param string $name
 *   Project name
 * @param array $config
 *   An array keyed by project name that can contain an override url.
 *
 * @return string
 *   Project url.
 */
function get_project_url($name, $config) {
  if (isset($config[$name]['url'])) {
    return $config[$name]['url'];
  }
  return DRUPAL_PROJECT_URL . '/' . $name;
}
