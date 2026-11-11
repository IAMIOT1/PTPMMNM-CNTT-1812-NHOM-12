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

$sourcePath = $storageRoot . '/upload.txt';
file_put_contents($sourcePath, "locale,source_text,target_text\nvi,Hello,Xin chao\n");
$extensionRejected = false;
try {
    $repository->importUploaded($sourcePath, 'translations.txt');
} catch (InvalidArgumentException $exception) {
    $extensionRejected = $exception->getMessage() === 'TM_REPOSITORY_CSV_EXTENSION_REQUIRED';
}
same($extensionRejected, true, 'non-csv-extension-is-rejected');

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
