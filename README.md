# simplePHPbackup
Backing up site files and database with only ftp-access.
Also can restore database from .sql/.sql.gz previously backed or just uploaded file
## Quick start

 - Edit ```backuper.php``` "settings"-section;
 - Upload ```backuper.php``` to any web-accessible location of your
   site;
 - Navigate to this just using browser.
 - (You can rename ```backuper.php``` to any needed name)
## Useful settings
```$params['backup_files']``` – true/false. Enables files backup.
```$params['backup_db']``` – true/false. Enables database backup.

```$params['pack_dir']``` – directory to backup files. Default is document_root.
```$params['exclude_folders']``` – array of excluded files/folders from backup.

```$params['gzip_db']``` – true / false. Try to compress database dump.
```$params['restore_db']``` – true / false. Add possibility to deploy databases. If "false", button "Restore DB" will be not exists.

```$params['filter_ip']``` – false / string. For security reasons, you can limit access to script by allowed IP's. Comma-separated, ```'0.0.0.0,1.1.1.1'``` for example.

```$params['backup_folder']``` – path to storing backups.

More settings in the script code.
