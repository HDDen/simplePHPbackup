# simplePHPbackup
Backing up site files and database with only ftp-access.<br/>
Also can restore database from .sql/.sql.gz previously backed or just uploaded file
## Quick start

 - Edit ```backuper.php``` "settings"-section (you can rename ```backuper.php``` to any needed name);<br/>
 - Upload ```backuper.php``` to any web-accessible location of your site;<br/>
 - Navigate to this just using browser.<br/>
## Useful settings
```$params['backup_files']``` – true/false. Enables files backup.<br/>
```$params['backup_db']``` – true/false. Enables database backup.<br/>

```$params['pack_dir']``` – directory to backup files. Default is document_root.<br/>
```$params['exclude_folders']``` – array of excluded files/folders from backup.<br/>

```$params['gzip_db']``` – true / false. Try to compress database dump.<br/>
```$params['restore_db']``` – true / false. Add possibility to deploy databases. If "false", button "Restore DB" will be not exists.<br/>

```$params['filter_ip']``` – false / string. For security reasons, you can limit access to script by allowed IP's. Comma-separated, ```'0.0.0.0,1.1.1.1'``` for example.<br/>

```$params['backup_folder']``` – path to storing backups.<br/>

More settings in the script code.
