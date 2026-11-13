<?php
declare(strict_types=1);

require __DIR__ . '/helper.php';
require __DIR__ . '/../src/TmRepository.php';

$storageRoot = sys_get_temp_dir() . '/tm-repository-test-' . bin2hex(random_bytes(6));
$repository = new TmRepository(null, $storageRoot);
same($repository->listFiles(), [], 'empty-repository-lists-no-files');

file_put_contents($storageRoot . '/existing.csv', "locale,source_text,target_text\nvi,Hello,Xin chao\n");
$files = $repository->listFiles();
same(count($files), 1, 'csv-file-is-listed');
same($files[0]['name'], 'existing.csv', 'listed-file-name-is-correct');
same($repository->getFileEntries('existing.csv'), [
    ['row_index' => 0, 'locale' => 'vi', 'source_text' => 'Hello', 'target_text' => 'Xin chao'],
], 'repository-file-details-are-readable');

$missingRowRejected = false;
try {
    $repository->updateFileTranslation('existing.csv', 4, 'Updated');
} catch (InvalidArgumentException $exception) {
    $missingRowRejected = $exception->getMessage() === 'TM_REPOSITORY_ENTRY_NOT_FOUND';
}
same($missingRowRejected, true, 'unknown-file-row-cannot-be-updated');

$sourcePath = $storageRoot . '/upload.txt';
file_put_contents($sourcePath, "locale,source_text,target_text\nvi,Hello,Xin chao\n");
$extensionRejected = false;
try {
    $repository->importUploaded($sourcePath, 'translations.txt');
} catch (InvalidArgumentException $exception) {
    $extensionRejected = $exception->getMessage() === 'TM_REPOSITORY_CSV_EXTENSION_REQUIRED';
}
same($extensionRejected, true, 'non-csv-extension-is-rejected');

$invalidHeaders = [
    "locale,source_text,translated_text\nvi,Hello,Xin chao\n",
    "locale,target_text,source_text\nvi,Xin chao,Hello\n",
    "locale,source_text,target_text,notes\nvi,Hello,Xin chao,note\n",
];
foreach ($invalidHeaders as $index => $csv) {
    $invalidCsvPath = sys_get_temp_dir() . '/tm-repository-invalid-' . $index . '-' . bin2hex(random_bytes(6)) . '.csv';
    file_put_contents($invalidCsvPath, $csv);
    $headerRejected = false;
    try {
        $repository->importUploaded($invalidCsvPath, 'invalid.csv');
    } catch (InvalidArgumentException $exception) {
        $headerRejected = $exception->getMessage() === 'TM_REPOSITORY_CSV_COLUMNS_REQUIRED';
    }
    same($headerRejected, true, 'noncanonical-csv-header-is-rejected-' . $index);
    unlink($invalidCsvPath);
}

$readCsv = new ReflectionMethod(TmRepository::class, 'readCsv');
$longSource = str_repeat('x', 1500);
$validCsvSamples = [
    [
        "locale,source_text,target_text\nvi,Hello,Xin chao\n",
        [['locale' => 'vi', 'source_text' => 'Hello', 'translated_text' => 'Xin chao']],
        'comma-csv-is-parsed',
    ],
    [
        "locale;source_text;target_text\nvi;{$longSource};Xin chao\n",
        [['locale' => 'vi', 'source_text' => $longSource, 'translated_text' => 'Xin chao']],
        'semicolon-csv-and-long-row-are-parsed',
    ],
];
foreach ($validCsvSamples as [$csv, $expectedRows, $testName]) {
    $validCsvPath = sys_get_temp_dir() . '/tm-repository-valid-' . bin2hex(random_bytes(6)) . '.csv';
    file_put_contents($validCsvPath, $csv);
    same($readCsv->invoke($repository, $validCsvPath), $expectedRows, $testName);
    unlink($validCsvPath);
}

$csvSourcePath = sys_get_temp_dir() . '/tm-repository-upload-' . bin2hex(random_bytes(6)) . '.csv';
file_put_contents($csvSourcePath, "locale,source_text,target_text\nvi,Hello,Xin chao\n");
try {
    $repository->importUploaded($csvSourcePath, 'translations.csv');
} catch (Throwable) {
}
same(count($repository->listFiles()), 1, 'failed-tm-import-removes-partial-storage-file');

unlink($storageRoot . '/existing.csv');
unlink($sourcePath);
unlink($csvSourcePath);
rmdir($storageRoot);
finish();
