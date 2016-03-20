#!/usr/bin/php
<?php
/**
 * Listet die Symbol-Informationen einer MetaTrader-Datei "symbols.raw" auf.
 *
 * @see Struct-Formate in MT4Expander.dll::Expander.h
 */
require(dirName(realPath(__FILE__)).'/../../config.php');


// -- Konfiguration --------------------------------------------------------------------------------------------------------------------------------


$files     = array();
$options   = array();
$fieldArgs = array();


// -- Start ----------------------------------------------------------------------------------------------------------------------------------------


// (1) Befehlszeilenargumente auswerten
$args = array_slice($_SERVER['argv'], 1);

// Hilfe ?
foreach ($args as $arg) {
   if ($arg == '-h') help() & exit(1);
}

// Optionen und Argumente parsen
foreach ($args as $i => $arg) {
   // -f=FILE
   if (strStartsWith($arg, '-f=')) {
      if ($files) help('invalid/multiple file arguments: -f='.$arg) & exit(1);
      $value = $arg = strRight($arg, -3);
      strIsQuoted($value) && ($value=strLeft(strRight($value, -1), 1));

      if (file_exists($value)) {             // existierende Datei oder Verzeichnis
         is_dir($value) && !is_file(($value.=(strEndsWith($value, '/') ? '':'/').'symbols.raw')) && help('file not found: '.$value) & exit(1);
         $files[] = $value;
      }
      else {                                 // Argument existiert nicht, Wildcards expandieren und Ergebnisse prüfen (z.B. unter Windows)
         strEndsWith($value, array('/', '\\')) && ($value.='symbols.raw');
         $entries    = glob($value, GLOB_NOESCAPE|GLOB_BRACE|GLOB_ERR);
         $matchesDir = false;
         foreach ($entries as $entry) {
            if (is_dir($entry) && ($matchesDir=true))
               continue;
            $files[] = $entry;               // nur Dateien übernehmen
         }
         !$files && help('file(s) not found: '.$arg.($matchesDir ? ' (enter a trailing slash "/" to search directories)':'')) & exit(1);
         uSort($files, 'compareFileNames');  // Datei-/Verzeichnisnamen lassen sich mit den existierenden Funktionen nicht natürlich sortieren
      }
      continue;
   }

   // count symbols
   if ($arg == '-c') {
      $options['countSymbols'] = true;
      continue;
   }

   // list available fields
   if ($arg == '-l') {
      $options['listFields'] = true;
      break;
   }

   // include all fields
   if ($arg == '++') {
      $fieldArgs = array('++');
      continue;
   }

   // include specific field
   if (strStartsWith($arg, '+')) {
      $key = subStr($arg, 1);
      if (!strLen($key)) help('invalid field specifier: '.$arg) & exit(1);
      unset($fieldArgs['-'.$key]);                                            // drops element if it exists
      if (!in_array('++', $fieldArgs) && !in_array('+'.$key, $fieldArgs))
         $fieldArgs[] = '+'.$key;
      continue;
   }

   // exclude specific field
   if (strStartsWith($arg, '-')) {
      $key = subStr($arg, 1);
      if (!strLen($key)) help('invalid field specifier: '.$arg) & exit(1);
      unset($fieldArgs['+'.$key]);                                            // drops element if it exists
      if (in_array('++', $fieldArgs) && !in_array('-'.$key, $fieldArgs))
         $fieldArgs[] = '-'.$key;
      continue;
   }

   // unrecognized arguments
   help('invalid argument: '.$arg) & exit(1);
}


// (2) ggf. verfügbare Felder anzeigen und danach abbrechen
$allFields = array_flip(MT4::SYMBOL_getFields());     // TODO: Feld 'leverage' dynamisch hinzufügen
$allFields = array_keys($allFields);                  // array_splice($fields, array_search('marginDivider', $fields)+1, 0, array('leverage'));

if (isSet($options['listFields'])) {
   echoPre($s='Available symbol fields:');
   echoPre(str_repeat('-', strLen($s)));
   foreach ($allFields as $field)
      echoPre(ucFirst($field));
   exit(0);
}


// (3) Default-Parameter setzen
if (!$files) {
   $file = 'symbols.raw';
   if (!is_file($file)) help('file not found: '.$file) & exit(1);
   $files[] = $file;
}


// (4) anzuzeigende Felder bestimmen
$allFieldsLower = array_change_key_case(array_flip($allFields), CASE_LOWER);  // lower-name => (int)
$usedFields     = array_flip($allFields);                                     // real-name  => (int)
foreach ($usedFields as &$value) {
   $value = null;                                                             // real-name  => (null)       default: alle Felder OFF
} unset($value);

foreach ($fieldArgs as $arg) {
   if ($arg == '++') {
      foreach ($usedFields as $name => &$value) {
         $value = $name;                                                      // real-name  => print-name   alle Felder ON
      } unset($value);
      continue;
   }
   if ($arg[0] == '+') {
      $name = strToLower(strRight($arg, -1));
      if (isSet($allFieldsLower[$name])) {
         $realName = $allFields[$allFieldsLower[$name]];
         $usedFields[$realName] = $realName;                                  // real-name => print-name    Feld ON
      }
   }
   else if ($arg[0] == '-') {
      $name = strToLower(strRight($arg, -1));
      if (isSet($allFieldsLower[$name])) {
         $realName = $allFields[$allFieldsLower[$name]];
         $usedFields[$realName] = null;                                       // real-name => (null)        Feld OFF
      }
   }
}
$usedFields['name'] = 'symbol';                                               // Symbol ist immer ON (kann nicht ausgeschaltet werden)

foreach ($usedFields as $name => $value) {
   if (is_null($value)) {                                                     // verbliebene NULL-Felder löschen
      unset($usedFields[$name]);
      continue;
   }
   $usedFields[$name] = null;
   $usedFields[$name]['printName'] = ucFirst($value);                         // [real-name][printName] => print-name
   $usedFields[$name]['length'   ] = strLen($value);                          // [real-name][length]    => (int)
}


// (5) Symbolinformationen erfassen und ausgeben (getrennt, damit Spalten übergreifend formatiert werden können)
$data = array();
foreach ($files as $file)
   collectData($file, $usedFields, $data, $options) || exit(1);
printData(    $files, $usedFields, $data, $options) || exit(1);

// Programmende
exit(0);


// --- Funktionen ---------------------------------------------------------------------------------------------------------------------------------------------


/**
 * Erfaßt die Informationen einer Symboldatei.
 *
 * @param  _In_     string $file    - Name der Symboldatei
 * @param  _In_Out_ array &$fields  - zu erfassende Felder (Längen werden im Array gespeichert)
 * @param  _In_Out_ array &$data    - Array zum Zwischenspeichern der erfaßten Daten
 * @param  _In_     array  $options - Optionen
 *
 * @return bool - Erfolgsstatus
 */
function collectData($file, array &$fields, array &$data, array $options) {
   // (1) Dateigröße prüfen
   $fileSize = fileSize($file);
   if ($fileSize < MT4::SYMBOL_SIZE) {
      $data[$file]['meta:error'] = 'invalid or unsupported format, file size ('.$fileSize.') < MinFileSize ('.MT4::SYMBOL_SIZE.')';
      return true;
   }
   if ($fileSize % MT4::SYMBOL_SIZE)
      $data[$file]['meta:warn'][] = 'file contains '.($fileSize % MT4::SYMBOL_SIZE).' trailing bytes';


   // (2) Länge des längsten Dateinamens speichern
   $data['meta:maxFileLength'] = max(strLen($file), isSet($data['meta:maxFileLength']) ? $data['meta:maxFileLength'] : 0);


   // (3) Anzahl der Symbole ermitteln und speichern
   $symbolsSize = (int)($fileSize/MT4::SYMBOL_SIZE);
   $data[$file]['meta:symbolsSize'] = $symbolsSize;
   if (isSet($options['countSymbols']))                                             // Die Meta-Daten liegen in derselben Arrayebene wie
      return true;                           // ggf. sofort zurückkehren            // die Symboldaten und müssen Namen haben, die mit den
                                                                                    // Feldnamen der Symbole nicht kollidieren können.

   // (4) Daten auslesen
   $hFile   = fOpen($file, 'rb');
   $symbols = array();
   for ($i=0; $i < $symbolsSize; $i++) {
      $symbols[] = unpack(MT4::SYMBOL_getUnpackFormat(), fRead($hFile, MT4::SYMBOL_SIZE));
   }
   fClose($hFile);


   // (5) Daten auslesen und maximale Feldlängen speichern
   $values = $lengths = array();
   foreach ($symbols as $i => $symbol) {
      foreach ($fields as $name => $v) {
         $value = isSet($symbol[$name]) ? $symbol[$name] : '?';                     // typenlose Felder (x) werden markiert
         if (is_double($value) && ($e=(int) strRightFrom($s=(string)$value, 'E-'))) {
            $decimals = strLeftTo(strRightFrom($s, '.'), 'E');
            $decimals = ($decimals=='0' ? 0 : strLen($decimals)) + $e;
            if ($decimals <= 14)                                                    // ab 15 Dezimalstellen wissenschaftliche Anzeige
               $value = number_format($value, $decimals);
         }
         $values[$name][]         = $value;                                         // real-name[n]      => value
         $fields[$name]['length'] = max(strLen($value), $fields[$name]['length']);  // real-name[length] => (int)
      }
   }
   $data[$file] = array_merge($data[$file], $values);

   return true;
}


/**
 * Gibt die eingelesenen Informationen aller Symboldateien aus.
 *
 * @param  string[] $files   - Symboldateien
 * @param  array    $fields  - auszugebende Felder
 * @param  array    $data    - auszugebende Daten
 * @param  array    $options - Programmoptionen
 *
 * @return bool - Erfolgsstatus
 */
function printData(array $files, array $fields, array $data, array $options) {
   $tableHeader = $tableSeparator = $fileSeparator = '';

   // (1) Tabellen-Header definieren
   foreach ($fields as $name => $value) {
      $tableHeader .= str_pad($value['printName'], $value['length'], ' ',  STR_PAD_RIGHT).'  ';
   }
   $tableHeader  = strLeft($tableHeader, -2);
   $countSymbols = isSet($options['countSymbols']);
   $sizeFiles    = sizeOf($files);


   foreach ($files as $i => $file) {
      // (2) Table-Header ausgeben
      $symbolsSize    = $data[$file]['meta:symbolsSize'];
      $sizeMsg        = $symbolsSize.' symbol'.($symbolsSize==1 ? '':'s');
      $tableSeparator = str_repeat('-', max(strLen($file), strLen($tableHeader), strLen($tableSeparator)));
      $fileSeparator  = str_repeat('=', strLen($tableSeparator));

      if ($countSymbols) {
         echoPre(str_pad($file.':', $data['meta:maxFileLength']+1, ' ',  STR_PAD_RIGHT).' '.$symbolsSize.' symbols');
         continue;
      }
      echoPre($file.':');
      echoPre($tableHeader);
      echoPre($tableSeparator);

      // (3) Daten ausgeben
      for ($n=0; $n < $symbolsSize; $n++) {
         $line = '';
         foreach ($fields as $name => $v) {
            $line .= str_pad($data[$file][$name][$n], $fields[$name]['length'], ' ',  STR_PAD_RIGHT).'  ';
         }
         $line = strLeft($line, -2);
         echoPre($line);
      }

      // (4) Table-Footer ausgeben
      echoPre($tableSeparator);
      echoPre($sizeMsg);
      if (++$i < $sizeFiles)
         echoPre($fileSeparator.NL.NL);
   }
   return true;
}


/**
 * Comparator, der zwei Dateinamen vergleicht. Mit den existierenden Funktionen lassen sich Datei- und Verzeichnisnamen
 * nicht natürlich sortieren (z.B. wie im Windows Explorer).
 *
 * @param  string $fileA
 * @param  string $fileB
 *
 * @return int - positiver Wert, wenn $fileA nach $fileB einsortiert wird;
 *               negativer Wert, wenn $fileA vor $fileB einsortiert wird;
 *               0, wenn beide Dateinamen gleich sind
 */
function compareFileNames($fileA, $fileB) {
   if ($fileA === $fileB) {
      echoPre(__FUNCTION__.'(1)  $fileA = $fileB:  '.$fileA.'  '.$fileB);
      return 0;
   }
   $lenA = strLen($fileA);
   $lenB = strLen($fileB);

   if (!$lenA) {
      echoPre(__FUNCTION__.'(2)  $fileA = "":  '.$fileA);
      return $lenB ? -1 : 0;
   }
   if (!$lenB) {
      echoPre(__FUNCTION__.'(3)  $fileB = "":  '.$fileB);
      return $lenA ? +1 : 0;
   }

   // beide Strings haben eine Länge > 0
   $fileALower = strToLower(str_replace('\\', '/', $fileA));
   $fileBLower = strToLower(str_replace('\\', '/', $fileB));
   $len   = min($lenA, $lenB);

   for ($i=0; $i < $len; $i++) {
      $charA = $fileALower[$i];
      $charB = $fileBLower[$i];

      if ($charA != $charB) {
         if ($charA == '/') return -1;
         if ($charB == '/') return +1;
         return ($charA > $charB) ? +1 : -1;
      }
   }

   // Kleinschreibung ist soweit identisch, Längen vergleichen
   if ($lenA == $lenB)
      return ($fileA > $fileB)        ? +1 : -1;   // gleiche Länge, Originalnamen vergleichen
   return ($fileALower > $fileBLower) ? +1 : -1;   // unterschiedliche Länge, Lower-Names vergleichen
}


/**
 * Hilfefunktion
 *
 * @param  string $message - zusätzlich zur Syntax anzuzeigende Message (default: keine)
 */
function help($message=null) {
   if (is_null($message))
      $message = 'List symbol information of MetaTrader "symbols.raw" files.';
   $self = baseName($_SERVER['PHP_SELF']);

echo <<<END
$message

  Syntax:  $self [-f=FILE] [OPTIONS]

            -f=FILE  File(s) to analyze (default: "symbols.raw").
                     If FILE contains wildcards symbols of the matching files will be analyzed.
                     If FILE is a directory "symbols.raw" in that directory will be analyzed.

  Options:  -c     Count symbols of the specified file(s).
            -l     List available SYMBOL fields.
            +NAME  Include the named field in the output.
            ++     Include all fields in the output.
            -NAME  Exclude the named field from the output.
            -h     This help screen.


END;
}