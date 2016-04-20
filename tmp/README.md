# The default tmp directory for the backup script.

Archives are stored here temporarily, in preparation for export to google drive.
While the tmp directory is configurable in settings.inc.php, please note that 
the script will attempt to remove anything left in the folder (with a `.gz` 
extension) after backups are complete, so be careful what you place here!
