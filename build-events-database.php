<?php

$versions = ['2.0', '2.1'];
$magentoProjectsFolder = './magento-projects';

$eventsDb = new SQLite3('./db/magento2events.db');

initDb();

// downloadMagentoProjects();

foreach($versions as $version) {
  echo "--- Indexing Magento $version files\n";
  $phpFiles = getPhpFiles($version);
  echo "--- Processing Magento $version files\n";
  foreach($phpFiles as $phpFile) {
    $events = searchForEvents($phpFile);
    if(!empty($events))
      storeEvents($version, $phpFile, $events);
  }
  echo "--- All Magento $version files processed\n";
}
echo "--- Sqlite Database ready, see: db/magento2events.db\n";

// deleteMagentoProjects();


function initDb() {
  global $eventsDb;

  echo "--- Creating Database ---\n";
  if(!$eventsDb)
    die($eventsDb->lastErrorMsg());

  if(!$eventsDb->exec('DROP TABLE IF EXISTS events'))
    die($eventsDb->lastErrorMsg());

  if(!$eventsDb->exec('CREATE TABLE events(name TEXT, magento_version CHAR(10), magento_module CHAR(50), file_url TEXT, starting_line INT, ending_line INT);'))
    die($eventsDb->lastErrorMsg());
  echo "--- Database created ---\n";
}

function downloadMagentoProjects() {
  global $versions, $magentoProjectsFolder;

  deleteMagentoProjects();

  mkdir($magentoProjectsFolder);

  foreach($versions as $version) {
    echo "--- Downloading Magento $version ---\n";
    exec("wget -O $magentoProjectsFolder/$version.zip https://github.com/magento/magento2/archive/$version.zip");
    echo "--- Magento $version downloaded ---\n";

    echo "--- Extracting Magento $version archive ---\n";
    $zip = new ZipArchive;
    if ($zip->open("$magentoProjectsFolder/$version.zip") === TRUE) {
      $zip->extractTo($magentoProjectsFolder);
      $zip->close();
      echo "Magento $version archive extracted\n";
    } else {
      die("Zip Extraction failed");
    }
  }
}

function deleteMagentoProjects() {
  global $magentoProjectsFolder;

  echo "--- Deleting Magento projects folders --- \n";
  exec("rm -rf $magentoProjectsFolder");
  echo "--- Magento projects folders deleted --- \n";
}

function getPhpFiles($version) {
  $paths = ["$version/app/code/Magento", "$version/lib/internal"];

  return array_merge(getPhpFilesByPath($paths[0]), getPhpFilesByPath($paths[1]));
}

function getPhpFilesByPath($path) {
  global $magentoProjectsFolder;
  $path = "$magentoProjectsFolder/magento2-" . $path;

  $allFilesIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
  $phpFilesIterator = new RegexIterator($allFilesIterator, '/^(.(?!\/Test\/))+\.php$/i', RecursiveRegexIterator::GET_MATCH);

  return array_map(function($item){return $item[0];}, iterator_to_array($phpFilesIterator, false));
}

function searchForEvents($filePath) {
  $processingFile = new SplFileObject($filePath, 'r') or die("Unable to open file!");
  $events = [];

  while(!$processingFile->eof()) {
    $currentLine = $processingFile->getCurrentLine();
    if(strpos($currentLine, 'eventManager->dispatch(') !== false) {
      $events[] = getEventInfos($processingFile, $currentLine);
    }
  }
  return $events;
}

function getEventInfos($processingFile, $currentLine) {
  $eventDispatchCode = $currentLine;
  $startingLine = $processingFile->key() + 1;
  if (strpos($eventDispatchCode, ';') === false) {
    do{
      $text = $processingFile->fgets();
      $eventDispatchCode .= $text;
    } while(strpos($text, ';') === false);
  }

  return [
    'name' => getEventName($eventDispatchCode),
    'starting_line' => $startingLine,
    'ending_line' => $processingFile->key() + 1
  ];
}

function getEventName($code) {
  $code = preg_replace('/[\\x0-\x20\x7f]/', '', $code);

  $regExps = [
    '/eventManager->dispatch\(\'([^\']+)\'/',
    '/eventManager->dispatch\("([^"]+)\{|"/',
    '/eventManager->dispatch\(\$[^\'|,|\[]+\'([^\']+)\'/',
    '/eventManager->dispatch\(\$[^"|,|\[]+"([^"]+)\{|"/'
  ];

  foreach($regExps as $regExp) {
    preg_match($regExp, $code, $matches);

    if(isset($matches[1]))
      return $matches[1];
    elseif(end($regExps) === $regExp)
      return 'NO_MATCH_FOUND';
  }
}

function storeEvents($version, $filePath, $events) {
  global $eventsDb, $processingVersion;

  preg_match('/app\/code\/Magento\/([^\/]+)\//', $filePath, $matches);
  $module = isset($matches[1]) ? $matches[1] : 'lib';

  $fileLocation = str_replace("./magento-projects/magento2-$version/", "", $filePath);

  $sqlQuery = 'INSERT INTO events VALUES';
  foreach($events as $event) {
    $sqlQuery .= sprintf(" ('%s','%s', '%s', '%s', '%s', '%s'),", $event['name'], $version, $module, $fileLocation, $event['starting_line'], $event['ending_line']);
  }
  $sqlQuery = substr($sqlQuery, 0, -1);
  $sqlQuery.= ';';

  $result = $eventsDb->exec($sqlQuery);
  if(!$result)
    die($eventsDb->lastErrorMsg());
}
