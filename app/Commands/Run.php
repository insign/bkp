<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use League\Flysystem\FileNotFoundException;
use PhpZip\Constants\ZipCompressionLevel;
use PhpZip\Constants\ZipCompressionMethod;
use PhpZip\Constants\ZipEncryptionMethod;
use PhpZip\ZipFile;
use Spatie\DbDumper\Databases\MongoDb;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Databases\PostgreSql;
use Spatie\DbDumper\Databases\Sqlite;

class Run extends Command
{

    // @TODO use .env as alternative
    // @TODO let set output file name
    // @TODO options to exclude some files
    // @TODO restore script
    // @TODO check https://www.php.net/manual/en/function.disk-free-space.php
    // @TODO add full support for mongodb
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'run
    {--D|destiny= : Disk name set in config/filesystems.php}
    {--E|encrypt= : Password to encrypt final .zip file}
    {--F|folder=* : Full path to folder to be included in final .zip file}
    {--S|sql=* : DB name set in config/database.php - supports MySQL, PostgreSQL, SQLite and MongoDB}
    {--M|mail= : E-mail to send the log}
    {--P|pushbullet= : E-mail the notification}
    ';

    /**
     * The description of the command.
     *
     * @var string
     */

    const SQL_DRIVERS = [ 'mysql', 'pgsql', 'sqlite', 'mongodb' ];
    protected $description = 'Runs backup routine';

    protected $now = NULL;
    protected $outputFilename = NULL;
    protected $disk = NULL;
    protected $disks = [];
    protected $password = NULL;
    protected $folders = [];
    protected $sqls = [];
    protected $email = NULL;
    protected $pushbullet = NULL;

    protected $zipFile = NULL;

    protected function constructor()
    {
        $this->now            = preg_replace('/[^a-zA-Z0-9-]/', '-', date('c'));
        $this->outputFilename = sprintf("%s_FILES.zip", $this->now);
        $this->disk           = $this->option('destiny');
        $this->disks          = collect(config('filesystems.disks'));
        $this->password       = $this->option('encrypt');
        $this->folders        = $this->option('folder');
        $this->sqls           = $this->option('sql');
        $this->email          = $this->option('mail');
        $this->pushbullet     = $this->option('pushbullet');
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->constructor();

        $this->task("Checking before start", function () {
            return $this->validateOrFail();
        });

        $this->backupFiles();

        $this->backupSQLs();

        $this->info('######################');
        $this->info('#### BACKUP DONE. ####');
        $this->info('######################');

        $this->notify("Backup done!", 'With success!');

        return TRUE;
    }

    /**
     * Define the command's schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     *
     * @return void
     */
    public function schedule(Schedule $schedule)
    : void
    {
        // $schedule->command(static::class)->everyMinute();
    }

    protected function validateOrFail()
    : bool
    {
        $dbs = collect(config('database.connections'));


        throw_unless(is_scalar($this->disk), new \InvalidArgumentException('Please, inform the disk of destiny.'));
        throw_unless($this->disks->has($this->disk), new \InvalidArgumentException("The disk '$this->disk' does not exist in filesystem.php file."));
        throw_if(empty($this->folders) && empty($this->sqls), new \InvalidArgumentException("Nothing to backup."));

        foreach ($this->folders as $folder) {
            throw_unless(is_dir($folder), new \InvalidArgumentException("The folder '$folder' is invalid."));
        }

        foreach ($this->sqls as $db) {
            throw_unless($dbs->has($db), new \InvalidArgumentException("The conn '$db' does not exist in database.php file."));
            throw_unless(in_array(config("database.connections.$db.driver"), self::SQL_DRIVERS), new \InvalidArgumentException("The driver of '$db' is not currently supported."));
        }

        // @FIXME Vefiry if mysqldump, pg_dump, sqlite3, mongodump etc exists in the system
        // @FIXME Vefiry phpzip

        return TRUE;
    }

    protected function backupFiles()
    : void
    {
        if (empty($this->folders)) {
            $this->alert('No FOLDERS informed to backup. Skipping...');
        }
        else {
            $this->info('######################');
            $this->info('### FOLDERS BACKUP ###');
            $this->info('######################');


            $this->zipFile = new ZipFile();
            $this->zipFile->setCompressionLevel(ZipCompressionLevel::MAXIMUM);

            foreach ($this->folders as $folder) {
                $basename = basename($folder);
                $this->task("Add $basename folder", function () use ($folder, $basename) {
                    $this->zipFile->addDirRecursive($folder, $basename, ZipCompressionMethod::BZIP2);

                    return TRUE;
                });
            }

            $this->task("Save $this->outputFilename file", function () {

                $this->zipFile->deleteFromGlob('**/vendor/**')->deleteFromGlob('**/node_modules/**');

                if ($this->password) {
                    $this->zipFile->setPassword($this->password, ZipEncryptionMethod::WINZIP_AES_256);
                }
                // @TODO encrypt only after all

                $this->zipFile->setCompressionLevel(ZipCompressionLevel::MAXIMUM)
                              ->saveAsFile($this->outputFilename)
                              ->close();

                throw_unless(file_exists($this->outputFilename), new FileNotFoundException($this->outputFilename));

                return TRUE;
            });
            $this->task("Move to disk $this->disk", function () {
                $basename = basename($this->outputFilename);
                Storage::disk($this->disk)->put($basename, fopen($this->outputFilename, 'rb'));

                $exists_on_destiny = Storage::disk($this->disk)->exists($basename);
                throw_unless($exists_on_destiny, new \Exception("File '$basename' not found on the disk '$this->disk'."));

                $size_on_local   = Storage::size($this->outputFilename);
                $size_on_destiny = Storage::disk($this->disk)->size($basename);

                throw_unless($size_on_local === $size_on_destiny, new \Exception("File '$basename' has different size on destiny."));

                Storage::delete($this->outputFilename);

                return TRUE;
            });
        }
    }

    protected function backupSQLs()
    {
        if (empty($this->sqls)) {
            $this->alert('No SQL informed to backup. Skipping...');
        }
        else {
            $this->info('######################');
            $this->info('##### SQL BACKUP #####');
            $this->info('######################');

            foreach ($this->sqls as $conn) {
                $config   = config("database.connections.$conn");
                $dumpfile = "{$this->now}_SQL_{$conn}.sql";

                $this->task("Dump SQL of '$conn'", function () use ($config, $dumpfile) {
                    switch ($config[ 'driver' ]) {
                        case 'pgsql':
                            PostgreSql::create()
                                      ->setHost($config[ 'host' ])
                                      ->setPort($config[ 'port' ])
                                      ->setDbName($config[ 'database' ])
                                      ->setUserName($config[ 'username' ])
                                      ->setPassword($config[ 'password' ])
                                      ->dumpToFile($dumpfile);
                            break;
                        case 'mysql':
                            MySql::create()
                                 ->setHost($config[ 'host' ])
                                 ->setPort($config[ 'port' ])
                                 ->setDbName($config[ 'database' ])
                                 ->setUserName($config[ 'username' ])
                                 ->setPassword($config[ 'password' ])
                                 ->dumpToFile($dumpfile);
                            break;
                        case 'sqlite':
                            Sqlite::create()->setDbName($config[ 'database' ])->dumpToFile($dumpfile);
                            break;
                        case 'mongodb':
                            MongoDb::create()
                                   ->setHost($config[ 'host' ])
                                   ->setPort($config[ 'port' ])
                                   ->setDbName($config[ 'database' ])
                                   ->setUserName($config[ 'username' ])
                                   ->setPassword($config[ 'password' ])
                                   ->dumpToFile($dumpfile);
                            break;
                        default:
                            throw new \Exception(sprintf("I do not support this SQL driver: %s", $config[ 'driver' ]));
                    }

                    return TRUE;
                });

                throw_unless(file_exists($dumpfile), new FileNotFoundException($dumpfile));

                $dumpzip = "$dumpfile.zip";
                $this->task("Zip to $dumpzip", function () use ($dumpfile, $dumpzip) {
                    $this->zipFile = new ZipFile();
                    $this->zipFile->setCompressionLevel(ZipCompressionLevel::MAXIMUM);
                    $this->zipFile->addFile($dumpfile, basename($dumpfile), ZipCompressionMethod::BZIP2);

                    if ($this->password) {
                        $this->zipFile->setPassword($this->password, ZipEncryptionMethod::WINZIP_AES_256);
                    }
                    $this->zipFile->setCompressionLevel(ZipCompressionLevel::MAXIMUM)->saveAsFile($dumpzip)->close();

                    throw_unless(file_exists($dumpzip), new FileNotFoundException($dumpfile));
                    Storage::delete($dumpfile);
                });

                $this->task("Move to disk $this->disk", function () use ($dumpzip) {
                    $basename = basename($dumpzip);
                    Storage::disk($this->disk)->put($basename, fopen($dumpzip, 'rb'));

                    $exists_on_destiny = Storage::disk($this->disk)->exists($basename);
                    throw_unless($exists_on_destiny, new \Exception("File '$basename' not found on the disk '$this->disk'."));

                    $size_on_local   = Storage::size($dumpzip);
                    $size_on_destiny = Storage::disk($this->disk)->size($basename);

                    throw_unless($size_on_local === $size_on_destiny, new \Exception("File '$basename' has different size on destiny."));

                    Storage::delete($dumpzip);

                    return TRUE;
                });
            }
        }
    }
}
