<!-- Cartographie de l'ancien moteur, établie le 3 octobre 2026 pour la refonte. Supprimée au lot 6, avec l'ancien code. -->

# Oueb WP Backup (fork of BackWPup 4.1.7) — functional map of the engine

Repo: `/home/user/forkbwpup`, branch `claude/new-session-11mtqu` (HEAD `a6677a3`). Read-only analysis.
All paths are relative to the repo root. "L" = line. **[BUG?]** marks behaviour that looks wrong and should not be copied blindly. **[UNCERTAIN]** marks things I could not fully verify statically.

---

## 0. Bootstrap (backwpup.php)

- Singleton `BackWPup` hooked on `plugins_loaded` prio 11 (backwpup.php:465).
- Guard `if (!is_main_network() && !is_main_site()) return;` (L41). Note: this only bails when *both* are false, so the plugin does load on sub-sites of the main network (only the cron runner re-checks `is_main_site`). **[BUG?]** — the comment says "only main site".
- Loads `inc/functions.php`, `inc/cronjob-org.php`, `inc/oueb-providers.php`, `vendor/autoload.php` (L45-50).
- Runs `BackWPup_Install::activate()` whenever `backwpup_version` differs from the header version or `backwpup_check_cleanup` is not scheduled (L53-57) — i.e. "activation" doubles as upgrade/self-repair on every request.
- Registers third-party cache-plugin exclusions (`BackWPup_ThirdParties::register()`, L60; see §3.2).
- In `DOING_CRON`: if `$_GET['backwpup_run']` → `BackWPup_Job::disable_caches()` + `wp_loaded` → `BackWPup_Cron::cron_active` (PHP_INT_MAX prio); else hooks `backwpup_cron` → `BackWPup_Cron::run` and `backwpup_check_cleanup` → `BackWPup_Cron::check_cleanup` (L63-77). Nothing else is loaded in cron.
- Admin: builds `BackWPup_Page_Settings([],[])` + `BackWPup_Admin` (L83-94); admin bar if `backwpup_cfg_showadminbar` (filter `backwpup_is_in_admin_bar`) (L100-105).
- WP-CLI: `WP_CLI::add_command('backwpup', BackWPup_WP_CLI)` (L109-110).
- `get_plugin_data()` (L149-218) computes runtime paths:
  - `hash` = site option `backwpup_cfg_hash` (6 hex chars from `md5`, regenerated if length not 6..12) (L171-178).
  - `temp` = `WP_TEMP_DIR/backwpup/{hash}/` if `WP_TEMP_DIR` defined, else `{uploads basedir}/backwpup/{hash}/temp/` (L179-192).
  - `running_file` = `{temp}backwpup-working.php` (L193).
  - `user-agent` = `"{name}/{version}; WordPress/{wp}; {home_url}"` (L204).
  - `backwpup_activation_time` site option initialised here (L206-209).
- Registered destinations (L291-434): FOLDER, FTP (needs `ftp_nb_fput`), SFTP (needs `phpseclib3\Net\SFTP`), KDRIVE (needs `curl_init`, `DOMDocument`), S3 (needs `curl_exec`, `XMLWriter`). Filter `backwpup_register_destination`. Each entry: `class`, `info{ID,name,description}`, `can_sync` (all **false** → sync mode is dead), `needed`, computed `error`.
- Job types (L441-461): `DBDUMP`, `FILE`, `DBCHECK`; filter `backwpup_job_types`.

---

## 1. Data model

### 1.1 Storage
- All jobs live in ONE site option `backwpup_jobs` = `array<int jobid, array<string key, mixed>>` (inc/class-option.php:84-117). Created as non-autoload `add_option(...,'no')` on single site, `add_site_option` on multisite (inc/class-install.php:15-19).
- `BackWPup_Option::get($jobid,$key,$default=null,$use_cache=true)` (class-option.php:129-177): falls back to `defaults_job($key)` when key missing. `use_cache=false` purges the object cache (`{network_id}:backwpup_jobs` in `site-options`, or `backwpup_jobs` + `alloptions`) (L88-102). Special cases: `archivename` → normalised with a freshly generated hash; `archivenamenohash` → archivename with literal `%hash%`; `archiveformat` `.tar.bz2` → `.tar.gz` (L167-172).
- `get_job($id)` = stored values merged over `defaults_job()` (L241-264). `update`/`delete`/`delete_job` always re-read uncached (L61-75, 274-308). `get_job_ids($key,$value)` filters by loose `==` (L318-343). `next_job_id()` = max id + 1 (L350-356).
- No locking on read-modify-write of `backwpup_jobs` (a running job writes `lastrun/logfile/...` while an admin may save) **[BUG?]**.

### 1.2 Job option keys (defaults from `BackWPup_Option::defaults_job` class-option.php:188-231, merged with each destination's and job type's `option_defaults()`)

Core (class-option.php:194-208):
| key | type | default | meaning / where used |
|---|---|---|---|
| `jobid` | int | (none; set on save) | written by edit page `class-page-editjob.php:48`; `start_http` checks it equals requested id (class-job.php:164) |
| `type` | string[] | `['DBDUMP','FILE']` | enabled job types; validated against registered types on save (editjob:54-62) |
| `destinations` | string[] | `[]` | destination IDs; emptied if no file-producing type (editjob:66-94) |
| `name` | string | `__('New Job')` | if empty/"New Job" on save → "Job with ID %d" (editjob:96-100) |
| `activetype` | `''|'wpcron'|'link'|'cronjoborg'` | `''` | start trigger (editjob:131-137). `''`=manual only |
| `logfile` | string abs path | `''` | last log file (set at job create, class-job.php:289; `.gz` appended by log compression, class-cron.php:105-110) |
| `lastbackupdownloadurl` | string URL | `''` | reset at job start (class-job.php:290), set by destinations after upload (e.g. class-destination-folder.php:195, -s3.php:913, -ftp.php:638, -sftp.php:289, -kdrive.php:204) |
| `cronselect` | `'basic'|'advanced'` | `'basic'` | UI mode of schedule editor |
| `cron` | string 5-field cron | `'0 3 * * *'` | schedule (editjob:139-194). Basic modes: mon/week/day/hour (minutes in steps of 5) |
| `mailaddresslog` | string (comma list) | admin email | log recipients; validated with `is_email` (editjob:102-111) |
| `mailaddresssenderlog` | string `Name <mail>` | `BackWPup {blogname} <admin_email>` | From header (class-job.php:1257-1281) |
| `mailerroronly` | bool | true | mail only if errors>0 (class-job.php:1236) — note warnings alone do NOT mail |
| `backuptype` | `'archive'|'sync'` | `'archive'` | always forced to `'archive'` on save (editjob:51-52) |
| `archiveformat` | `'.zip'|'.tar'|'.tar.gz'` | `'.tar'` (but save falls back to `.zip` for invalid input, editjob:118-123) | `.tar.bz2` legacy mapped to `.tar.gz` |
| `archivename` | string with date vars + `%hash%` | `'%Y-%m-%d_%H-%i-%s_%hash%'` | see 1.4 |
| `archivenamenohash` | derived | same | read-only alias |
| `archiveencryption` | bool | (no default!) | if truthy adds ENCRYPT_ARCHIVE step (class-job.php:355). **No UI sets it anymore** (editjob:124-125 comment); only values imported from BackWPup Pro work |
| `lastrun` | int (local ts) | — | class-job.php:288 |
| `lastruntime` | int seconds | — | class-job.php:1190-1191 |
| `cronjoborgid` | int | — | remote cron-job.org job id (inc/cronjob-org.php:162-215) |

DBDUMP (inc/class-jobtype-dbdump.php:114-132):
| `dbdumpexclude` | string[] table names | all tables NOT starting with `$wpdb->prefix` | tables NOT to dump (computed from `SHOW TABLES` at default time) |
| `dbdumpfile` | string | `sanitize_file_name(DB_NAME)` | base name of dump file (date vars allowed) |
| `dbdumptype` | string | `'sql'` | only sql exists (written to manifest) |
| `dbdumpfilecompression` | `''|'.gz'` | `''` | gzip the dump |
| `dbdumpdbcharset` | string | (no default) | only copied into manifest (class-job.php:2303); NOT passed to the dumper |

FILE (inc/class-jobtype-file.php:36-81):
| `backupexcludethumbs` | bool | false | skip `-NNNxNNN.(jpg|png|gif|webp)` in uploads (class-job.php:2152-2160) |
| `backupspecialfiles` | bool | true | add wp-config.php (+ parent dir variant) and, if root not backed up, `.htaccess nginx.conf .htpasswd robots.txt favicon.ico Web.config` |
| `backuproot` / `backupcontent` / `backupplugins` / `backupthemes` / `backupuploads` | bool | true | which WP roots to walk |
| `backuprootexcludedirs` | string[] (dir names) | `['logs','usage','restore','restore_temp']` + filter `backwpup_root_exclude_dirs` | |
| `backupcontentexcludedirs` | string[] | `cache wflogs logs upgrade w3tc updraft ai1wm-backups snapshots wp-clone ithemes-security backwpup-restore` + filter | |
| `backuppluginsexcludedirs` | string[] | `['backwpup','backwpup-pro']` + filter **(note: the fork's own plugin folder name is not in the list)** | |
| `backupthemesexcludedirs` | string[] | `[]` + filter | |
| `backupuploadsexcludedirs` | string[] | `[basename(logfolder)]` + filter | |
| `fileexclude` | string comma list | `''` | substrings (case-insensitive `stripos`) matched against full path, for files and dirs |
| `dirinclude` | string comma list of abs paths | `''` (filter `backwpup_dir_include`) | extra folders (realpath-validated on save) |
| `backupabsfolderup` | bool | false | treat `dirname(ABSPATH)` as WP root (affects in-archive paths too) |

DBCHECK (inc/class-jobtype-dbcheck.php): `dbcheckwponly` bool true, `dbcheckrepair` bool false.

Destinations (option_defaults):
- FOLDER (class-destination-folder.php:12): `maxbackups`=15, `backupdir`= `uploads/backwpup/{hash}/backups/` stored **relative to WP_CONTENT_DIR** (L292-304), `backupsyncnodelete`=true.
- FTP (class-destination-ftp.php): `ftphost`, `ftphostport` 21, `ftptimeout` 90, `ftpuser`, `ftppass` (encrypted), `ftpdir` `{slug}/`, `ftpmaxbackups` 15, `ftppasv` true, `ftpssl` false, `ftpsyncnodelete` (read).
- SFTP (class-backwpup-destination-sftp.php): `sftphost`, `sftpport` 22, `sftpuser`, `sftpauth` `'password'|key`, `sftppass`, `sftpkey`, `sftpkeypass`, `sftpfingerprint` (TOFU, cleared when host changes), `sftpdir`, `sftpmaxbackups` 15, `sftptimeout` 30.
- KDRIVE: `kdriveid` (digits), `kdriveemail`, `kdrivepassword` (encrypted), `kdrivedir` `Sauvegardes/{slug}/`, `kdrivemaxbackups` 15.
- S3: `s3base_url`, `s3base_multipart` true, `s3base_pathstylebucket` false, `s3base_region` (read), `s3accesskey`, `s3secretkey`, `s3bucket`, `s3region` `'scaleway-fr-par'`, `s3ssencrypt`, `s3storageclass`, `s3dir`, `s3maxbackups` 15, `s3syncnodelete` true.
- Secrets in job options are stored with `BackWPup_Encryption::encrypt` (see §5.4).

### 1.3 Site options (`backwpup_cfg_*` and others)
Defaults: `BackWPup_Option::default_site_options()` (class-option.php:13-50, `add_site_option` = only if missing). Saved by `BackWPup_Page_Settings::save_post_form()` (inc/class-page-settings.php:324-467).

| option | default | meaning | readers |
|---|---|---|---|
| `backwpup_version` | `'0.0.0'` → plugin version | upgrade marker | backwpup.php:53, install.php:107 |
| `backwpup_jobs` | `[]` | all jobs | §1.1 |
| `backwpup_cfg_showadminbar` | true (**[BUG]** form field `showadminbarmenu` no longer rendered → every settings save sets it false; same for `showfoldersize`, `protectfolders`, `gzlogs`, `keepplugindata`, settings:374-402) | admin bar menu | backwpup.php:100 |
| `backwpup_cfg_showfoldersize` | false | show folder sizes in File tab | class-file.php:82 |
| `backwpup_cfg_protectfolders` | true | write .htaccess / index.php / Web.config into created folders | class-file.php:196, 269-300 |
| `backwpup_cfg_keepplugindata` | false | saved but **never read** (uninstall always deletes) | — |
| `backwpup_cfg_jobmaxexecutiontime` | 30 (0..300) | seconds before self-restart; 0 = no restart | class-job.php:1384, 2210, 2241 |
| `backwpup_cfg_jobstepretry` | 3 (1..99/100) | max tries per step | class-job.php:1693 |
| `backwpup_cfg_jobrunauthkey` | random 32 alnum | secret for external start URL (`runext`), min 32 chars enforced on save (settings:404-422) | class-cron.php:221, class-job.php:1469 |
| `backwpup_cfg_loglevel` | `normal_translated` | `normal|normal_translated|debug|debug_translated` | class-job.php:155, 404 |
| `backwpup_cfg_jobwaittimems` | 0 | µs sleep on each progress update (≤500000) | class-job.php:1012 |
| `backwpup_cfg_jobdooutput` | 0 | echo 12 spaces + flush on every update (FCGI keep-alive) | class-job.php:1026 |
| `backwpup_cfg_windows` | 0 | path fixer for IIS (`wp-content/..`) | class-path-fixer.php:23 |
| `backwpup_cfg_maxlogs` | 30 | keep N newest `backwpup_log_*.html*` | class-job.php:1113-1157 |
| `backwpup_cfg_gzlogs` | 0 | gzip finished logs in cleanup cron (filter `backwpup_gz_logs`) | class-cron.php:85 |
| `backwpup_cfg_logfolder` | `uploads/backwpup/{hash}/logs/` relative to WP_CONTENT_DIR | log dir; relative paths are resolved against WP_CONTENT_DIR (`BackWPup_File::get_absolute_path`, class-file.php:123-139); save rejects absolute paths & `..` above root (class-file.php normalize_path) | |
| `backwpup_cfg_httpauthuser` / `_httpauthpassword` | `''` | legacy, created but unused | |
| `backwpup_cfg_authentication` | `{method:'',basic_user:'',basic_password:'',user_id:0,query_arg:''}` | how the plugin authenticates its own loopback HTTP calls (§2.8). `basic_password` encrypted | class-job.php:1433-1527 |
| `backwpup_cfg_hash` | 6-char random | site hash (temp/log/backup dirs, archive names) | backwpup.php:171 |
| `backwpup_cfg_encryption` | (none) | `'symmetric'|'asymmetric'` — **no UI in fork** | class-job.php:1949, class-wp-cli.php:159,215 |
| `backwpup_cfg_encryptionkey` | (none) | hex AES-256 key (64 hex) — no UI | class-job.php:1966 |
| `backwpup_cfg_publickey` | (none) | RSA public key PEM — no UI | class-job.php:2018 |
| `backwpup_activation_time` | time() | informational | backwpup.php:206 |
| `backwpup_messages` | `[]` | queued admin notices `{updated:[], error:[]}`; written by `BackWPup_Admin::message()` (class-admin.php:68-83), flushed by `display_messages()` (L102-146). Network-wide, not per-user **[BUG?]** | |
| `oueb_cronjob_org_key` | — | encrypted cron-job.org API key (editjob:203-205, cronjob-org.php) | |
| `license_*` | — | deleted on reset only (leftover) | settings:353-356 |

"Reset to defaults" (settings:331-361) deletes all cfg options incl. `backwpup_cfg_hash` (⇒ new hash ⇒ new temp/log/backup dir names!) then re-adds defaults and resyncs cron-job.org.

### 1.4 Archive name / hash
- `substitute_date_vars()` (class-option.php:476-523) replaces `%d %j %m %n %Y %y %a %A %B %g %G %h %H %i %s` with PHP `date()` values of local `current_time('timestamp')`, then `BackWPup_Job::sanitize_file_name` (class-job.php:682-721: strips `?[]/\=<>:;,'"&$#*()|~\`!{}` and NUL, spaces/`%20`/`+`→`_`, newlines/tabs→`-`, trims `.-_`).
- `%hash%` → `get_generated_hash($jobid)` = Base32(pack(rand byte, 6-hex site hash, rand byte)) (8 chars) + `sprintf('%02d',$jobid)` (class-option.php:420-432). `decode_hash()` (L443-467) recovers `[sitehash, jobid]` from a filename → used by destinations (`is_backup_owned_by_job`, class-destinations.php:157-190) to list/prune only this job's archives. Legacy base36 and pre-3.4.1 forms supported.
- `normalize_archive_name` (L370-413) appends `_%hash%` if missing; legacy `backwpup_...` names rewritten.
- Files in archive listing are recognised by extension `.tar.gz|.tar|.zip` (class-destinations.php:16-20, 140).

### 1.5 Runtime files, transients, cron hooks
- Running file `{temp}backwpup-working.php` = `'<?php //' . serialize(clone $job)` (class-job.php:723-733); read back skipping 8 bytes and `unserialize` (L218-238). Its existence == "a job is running"; deleting it == abort.
- Folder list `{temp}backwpup-{hash}-folder.php` (lines `//<abs folder>`) (class-job.php:2811-2832, read 2080-2103).
- Generic JSON storage `{temp}backwpup-{hash}-{name}.json` (`data_storage`, L2785-2803) — unused in fork.
- `{temp}manifest.json`, `{temp}backwpup_readme.txt`, DB dump file in `{temp}`; temp is emptied (files only, keeping `.htaccess nginx.conf index.php .donotbackup`) at job end and in cleanup cron (L1309-1335).
- `.donotbackup` file written into temp/log/backup folders (class-file.php:309-322); any directory containing one is skipped by the file walker.
- Site transients: `backwpup_cookies` (2 days; auth cookies for loopback "user" auth, class-job.php:1506-1524; deleted on settings save), `backwpup_{jobid}_{dest}` file lists per destination (1 year; e.g. class-destination-s3.php:584-626, -ftp.php:197-238, -sftp.php:314-388, -kdrive.php:228-302) and `backwpup_{jobid}_{s3|ftp|sftp|kdrive}` (written after upload). `delete_transient('doing_cron')` before loopback (class-job.php:1555).
- Cron events: `backwpup_cron` with `['arg'=>jobid]` (single events, rescheduled each run) and `['arg'=>'restart']` (every 60 s while a job runs); `backwpup_check_cleanup` twicedaily (install.php:36-38).
- Restore runtime: `{uploads}/backwpup-restore/` with `restore.log`, `restore.dat` (serialized registry), `extract/`, `uploads/` (§6) plus PHP `$_SESSION`.
- User option `backwpuplogs_per_page`, `backwpupbackups_per_page` (screen options).

---

## 2. Job execution engine (inc/class-job.php, inc/class-cron.php)

### 2.1 Entry points / start types
| start type | origin | path |
|---|---|---|
| `runnow` | "Run now" link in admin → `page=backwpupjobs&action=runnow&jobid=N&_wpnonce` (nonce action `backwpup_job_run-runnowlink`) → `BackWPup_Page_Jobs::load` (class-page-jobs.php:429-501) checks cap `backwpup_jobs_start`, temp/log folders, destinations `can_run`; then `get_jobrun_url('runnow',$jobid)` fires a non-blocking loopback POST to `wp-cron.php?backwpup_run=runnow&jobid=N&_nonce=…`; admin request polls the job `logfile` option for 10 s (40×250 ms) to detect start | |
| `runnowalt` | same link when `ALTERNATE_WP_CRON` (class-job.php:1485-1493): browser goes directly to wp-cron.php; `start_http` sends a redirect to the jobs page then continues (L181-191) | |
| `cronrun` | WP-Cron `backwpup_cron` arg=jobid → `BackWPup_Cron::run` (class-cron.php:11-54): only if job `activetype=wpcron`; if a job is already running, reschedule +300 s; else schedule next occurrence (`cron_next`) and call `cron_active(['run'=>'cronrun'])` directly (no HTTP). URL-supplied `cronrun` is rejected (class-cron.php:224-229, fork hardening) |
| `runext` | external URL `wp-cron.php?_nonce={jobrunauthkey}&backwpup_run=runext&jobid=N` (+ basic-auth creds in URL / query_arg) (class-job.php:1468-1475); allowed only for jobs with `activetype` `link` or `cronjoborg` (class-cron.php:237-245) |
| `restart` | loopback from `do_restart()` or `backwpup_cron` arg `restart` → `cron_active(['run'=>'restart'])` → restarts if no PID or no progress for >300 s (class-cron.php:200-215). `restartalt` URL used by JS polling under ALTERNATE_WP_CRON |
| `test` | loopback test, answers `BackWPup test request` (class-cron.php:196-198) |
| `runcli` | `wp backwpup start N` → `start_cli()` (class-job.php:2370-2419): defines DOING_CRON, checks job id, folders, refuses if running file exists, runs synchronously, never restarts |

Nonce for loopback start types (`runnow`, `test`, `restart`-from-URL…): `substr(wp_hash(wp_nonce_tick().'backwpup_job_run-'.$run,'nonce'),-12,10)` — a user-independent time-tick token (class-job.php:1447, class-cron.php:218). `restart`/`test` URLs carry no nonce (L1500-1502); `cron_active` handles `restart` before the nonce check (only restarts an existing stale job). `hash_equals` comparison (L230).

`start_http($starttype,$jobid)` (class-job.php:152-211): loads text domain according to loglevel, verifies job id and folders (non-restart), random sleep 100–900 ms "to prevent doubled running", `get_working_data()`; if none and start type valid → schedule `backwpup_cron restart` in +60 s, `new self(); create(); run()`.

### 2.2 `create()` — step plan (class-job.php:262-633)
- Loads job (`get_job`), sets `start_time`, log file name `{logfolder}backwpup_log_{6 random}_{Y-m-d_H-i-s}.html` (L283-286), updates `lastrun`, `logfile`, clears `lastbackupdownloadurl` (L288-290).
- `exclude_from_backup` = `explode(',', fileexclude)` filtered by `backwpup_file_exclude` + always `.tmp .svn .git desktop.ini .DS_Store /node_modules/` (L298-306).
- `steps_todo` order (each step has `steps_data[STEP]['NAME','STEP_TRY','SAVE_STEP_TRY']`):
  1. `CREATE` (already done at end of create)
  2. `JOB_{TYPE}` for each enabled type whose `creates_file()` is true, in registration order: `JOB_DBDUMP`, `JOB_FILE` (L313-324)
  3. if any file-producing type: `CREATE_MANIFEST` (L328-331)
  4. if `backuptype=archive`: compute `backup_folder` (= FOLDER dest `backupdir` absolutised, else TEMP) and `backup_file` = `generate_filename(archivename, archiveformat)` (L333-348); `CREATE_ARCHIVE`; `ENCRYPT_ARCHIVE` if `archiveencryption` (L350-360)
  5. `DEST_{ID}` for each selected destination with `can_run($job)` true (or `DEST_SYNC_{ID}` for sync+can_sync, dead) (L363-384)
  6. `JOB_{TYPE}` for non-file types: `JOB_DBCHECK` (L387-396)
  7. `END` (STEP_TRY preset 1) (L397-399)
- Writes running file, sets log level, writes HTML log header (§2.6), logs environment in debug mode, schedules missing wpcron run (L483-491).
- If file types but no DEST step → error "No destination correctly defined", `steps_todo=['END']`; if backup folder fails `check_folder` → same (L602-629).

### 2.3 `run()` main loop (class-job.php:1566-1751)
- Drops output buffers; if no steps/logfile → delete running file and return.
- **Double-run guard / locking**: if `pid` set and last update < 300 s → return (another worker owns it); if >300 s → warning "restarts due to inactivity" and take over (L1589-1594). There is no file lock/flock; the guard relies on `pid` stored in the running file plus the random sleep.
- Sets `timestamp_script_start`, `pid` (`posix_getpid`/`getmypid`), `uniqid`, writes running file; ini tweaks (display_errors 0, log_errors 1, error_log=logfile in debug, memory_limit=`WP_MAX_MEMORY_LIMIT` via `admin_memory_limit`, `TMPDIR`=temp) (L1596-1617).
- `set_error_handler([$this,'log'])` (all, or E_ALL^E_NOTICE in normal mode), `set_exception_handler`, `add_action('shutdown',[$this,'shutdown'])` (L1619-1628).
- pcntl signals (if available): HUP INT QUIT ILL ABRT BUS FPE SEGV TERM STKFLT USR1 USR2 XCPU XFSZ PWR SYS → `signal_handler` (logs with severity table L2525-2681, sets `$this->signal`), `declare(ticks=1)`; filter `backwpup_job_signals_to_handel` (L1630-1674).
- For each step not in `steps_done`: `step_percent = done/todo*100`; inner `while(true)`:
  - if `STEP_TRY >= backwpup_cfg_jobstepretry` → error "Step aborted: too many attempts!", mark done, `do_restart()`, break (L1693-1701).
  - `++STEP_TRY`; dispatch: `CREATE_ARCHIVE`→`create_archive()`, `ENCRYPT_ARCHIVE`→`encrypt_archive()`, `CREATE_MANIFEST`→`create_manifest()`, `END`→`end()` (exits), `JOB_x`→`$jobtype->job_run($this)` (or `skip_removed_step` warning if type gone, L1347-1359), `DEST_SYNC_x`→`job_run_sync`, `DEST_x`→`$dest->job_run_archive($this)` (or skip), else `CALLBACK` (L1707-1733).
  - On `true`: clear `temp`, push to `steps_done`, reset substeps, `update_working_data(true)`. If more than one step remains → `do_restart()` (restart between steps when allowed). Break on success; on `false` loop retries (L1736-1748).
- Substep progress: each step sets `substeps_todo`/`substeps_done`; `substep_percent` computed in `update_working_data` (L1038-1042).

### 2.4 Restart / resume mechanism
- `update_working_data($must)` (L1007-1057): optional usleep (`jobwaittimems`), `need_free_memory('10M')` (raises memory_limit), throttled to 1/s unless `$must`; optional keep-alive output; `$wpdb->check_connection(false)`; `set_time_limit(300)`; if running file vanished and step≠END → `end()` (abort path); else rewrite running file; if a signal was caught → `do_restart()`.
- `get_restart_time()` (L2204-2219): CLI or `jobmaxexecutiontime=0` → 300; else `max - elapsed - 3`.
- `do_restart_time($now=false)` (L2228-2257): CLI → 300. If signal or `$now` or elapsed ≥ max-3: `SAVE_STEP_TRY = STEP_TRY; --STEP_TRY` (so the interrupted try is not counted and the "N. Trying…" log line is not repeated — every step logs only when `SAVE_STEP_TRY != STEP_TRY`), then `do_restart(true)`.
- `do_restart($must)` (L1366-1420): no restart if step is END or only the last step remains (`count(done)+1 >= count(todo)`), CLI, (no max time and not must), (<3 s since start and not must), or running file missing. Otherwise: `pid=0`, `uniqid=''`, write running file, remove shutdown hook, reschedule `backwpup_cron restart` +5 s, `get_jobrun_url('restart')` (non-blocking loopback) and `exit()`.
- `shutdown()` (L2690-2726): logs fatal `error_get_last`, pcntl/posix last error, then `do_restart(true)` — i.e. a PHP fatal/timeout triggers a loopback restart.
- Watchdogs:
  - `backwpup_cron` arg `restart` every 60 s (scheduled in start_http L203 and re-scheduled in `BackWPup_Cron::run` L19) → restart if `pid` empty or >300 s without update.
  - `BackWPup_Cron::check_cleanup()` (class-cron.php:59-143, twicedaily + at job end): if running job made no progress for >3600 s → logs "Aborted, because no progress for one hour!", deletes running file, `update_working_data()` → `end()`. Compresses `.html` logs to `.html.gz` (if gzlogs and no job running) and fixes job `logfile` options; if no job running: clear restart hook and clean temp; re-schedules missing wpcron events for active jobs.
- Abort: `BackWPup_Job::user_abort()` (L2492-2509) deletes the running file; if job seems idle (no pid or not updated for > max execution time, default 60) it calls `update_working_data()` itself which triggers `end()` with "Aborted by user!".

### 2.5 `end()` (L1103-1304)
- Logs "Aborted by user!" if running file is gone; prunes logs beyond `maxlogs` (by mtime, filename `backwpup_log_*.html*`); final message (errors → E_USER_ERROR "Job has ended with errors…", warnings → warning, else "Job done in %s seconds.").
- Saves `lastruntime`; rewrites header metas `backwpup_jobruntime` and `backwpup_backupfilesize` in place (fixed-width 100-char lines).
- Mail: if `mailaddresslog` non-empty and not (`errors===0 && mailerroronly`): `wp_mail(to, "[SUCCESSFUL|WARNING|ERROR] BackWPup log {d-M-Y H:i}: {name}", log HTML, Content-Type text/html + From)`.
- Cleans temp, restores handlers, appends `</body></html>`, calls `BackWPup_Cron::check_cleanup()`, `exit()`.
- NOTE: the running file itself is not explicitly deleted in `end()`; it is in temp so `clean_temp_folder()` removes it (it is a regular file named `backwpup-working.php`).

### 2.6 Logs
- Format: one HTML file per run. Header (L418-445): `<meta>` tags `date` (ISO), `backwpup_errors`, `backwpup_warnings` (padded to 100 chars for in-place update), `backwpup_jobid`, `backwpup_jobname`, `backwpup_jobtype` (`DBDUMP+FILE`), `backwpup_backupfilesize`, `backwpup_jobruntime`; body black background monospace. Lines: `<span datetime="ISO" [title="[Type|Line|File|Mem|Mem Max|Mem Limit|PID|UniqID|Queries]" in debug]>[d-M-Y H:i:s]</span> message<br />` (L866-899). Errors/warnings wrapped in coloured spans; meta counters updated in place (L906-938).
- `read_logheader($file)` (L2446-2490) parses metas via `get_meta_tags` (supports `.gz` through `compress.zlib://`) → `{logtime, errors, warnings, jobid, name, type, runtime, backupfilesize}`.
- Location: `backwpup_cfg_logfolder`; retention `maxlogs`; compression `gzlogs` (class-cron.php:85-122). Log messages are also echoed to WP-CLI (coloured) or STDOUT in CLI (L842-864).
- `log()` accepts swapped args (type,message) (L753-778); arrays/objects are JSON-encoded.

### 2.7 PHP limits handling
- `set_time_limit(300)` on every update; restart before `jobmaxexecutiontime-3`; `need_free_memory` raising `memory_limit` dynamically (L1064-1076); `memory_limit` set to `WP_MAX_MEMORY_LIMIT`; files >2 GiB (or size unreadable) skipped with warning (L2173-2187); archive larger than `PHP_INT_MAX` aborted (L1912-1920, class-create-archive.php:796-837).

### 2.8 Loopback HTTP (`get_jobrun_url`, class-job.php:1431-1561)
- Base URL `site_url('wp-cron.php')`; query args `_nonce`, `doing_wp_cron` (microtime), `backwpup_run`, `jobid`.
- Auth (`backwpup_cfg_authentication.method`):
  - `basic`: header `Authorization: Basic base64(user:decrypt(pass))`; for `runext` creds also injected in URL `https://user:pass@host` (L1459-1475).
  - `query_arg`: appends `?{query_arg}` raw string to wp-cron URL (L1464-1466).
  - `user`: generates a logged-in auth cookie for the first `administrator` (or `backwpup_admin`) user via `WP_Session_Tokens` and caches it in site transient `backwpup_cookies` 2 days (L1504-1527) — note: `user_id` setting is ignored, the first admin is used **[BUG?]**.
- Request args: `blocking=false, sslverify=false, timeout=0.01`, UA plugin UA, filter `cron_request` applied; `test` → blocking 15 s. Types `runnowlink`, `runext`, `restartalt` only return `['url','key','args']`; others are fired via `wp_remote_post` (L1554-1560).
- cron-job.org integration (`inc/cronjob-org.php`, `inc/class-oueb-cronjob-org.php`): for `activetype=cronjoborg`, a remote job is created/updated calling the `runext` URL (credentials stripped from URL and sent as basic auth), schedule derived from `cron` and site timezone (`oueb_cronjob_org_timezone`); synced on job save, settings change, activation; removed on job delete/deactivation.

### 2.9 Cron expression evaluation (`BackWPup_Cron::cron_next`, class-cron.php:258-387)
Own parser: lists `,`, ranges `a-b`, steps `/n`, `*`; minimum minute step 5 for ranges and 10 for `*` (!), wday 7→0; values >60 invalid → `PHP_INT_MAX`. Brute-force search over 100 years; returns GMT ts = local wall time − `gmt_offset` hours (integer cast: half-hour offsets truncated **[BUG?]**, `timezone_string` DST ignored).

---

## 3. Job types

### 3.1 DBDUMP (inc/class-jobtype-dbdump.php + inc/class-mysqldump.php)
- Own mysqli connection (not `$wpdb`): `BackWPup_MySQLDump` (class-mysqldump.php:74-147) with Symfony OptionsResolver defaults from `DB_HOST` (port/socket parsed from `host:port|socket`), `DB_USER`, `DB_PASSWORD`, `DB_NAME`, charset `DB_CHARSET` (utf8→utf8mb4 upgrade, fallback, L227-249), `MYSQL_CLIENT_FLAGS`, connect timeout 5 s, `MYSQLI_REPORT_STRICT` during connect.
- Output file `{temp}{generate_db_dump_filename(dbdumpfile,'sql')}{dbdumpfilecompression}` (dbdump.php:223-225, filter `backwpup_generate_dump_filename`); `.gz` → written through `compress.zlib://` stream opened in append mode (mysqldump.php:101-110).
- Table list: `SHOW TABLE STATUS` + `SHOW FULL TABLES`; views recorded separately (L121-146). Excludes `dbdumpexclude`. `substeps_todo` = #tables.
- Dump: header with comments + `SET` statements (`NAMES`, `TIME_ZONE`, `UNIQUE_CHECKS=0`, `FOREIGN_KEY_CHECKS=0`, `SQL_MODE='NO_AUTO_VALUE_ON_ZERO'`, `SQL_NOTES=0`) (L388-423). Per table: `DROP TABLE IF EXISTS` + `SHOW CREATE TABLE` (double quotes → backticks), `LOCK TABLES … WRITE; ALTER TABLE … DISABLE KEYS` (L478-546). Views get a placeholder table first and real `CREATE VIEW` in the footer. Data: `SELECT * FROM t LIMIT start,length` unbuffered (L944-958); values: NULL, numeric unquoted, date types quoted, binary → `0x` hex, else `real_escape_string`; extended INSERTs split at ~50 000 chars (L597-673). Footer: views, functions, procedures, triggers (`SHOW … STATUS` + `SHOW CREATE …` with DELIMITER ;;), restore session vars, "Backup completed on" (L428-470, 680-860).
- Chunking/resume (dbdump.php:254-304): `steps_data['JOB_DBDUMP']['tables'][$table] = {start,length}`; first chunk 1000 rows, then length adapted to `rows/sec × remaining restart time`, clamped 1000..25000; `do_restart_time()` after each chunk; header written once (`is_head`). Already-finished tables skipped by `substeps_done` index. Restart re-opens the file in append mode (the in-flight chunk is always complete because restart happens between chunks).
- Result: dump path appended to `additional_files_to_backup` (L321) → put at archive root. Log "Database backup done!".
- Dashboard one-click "Download database backup" (class-page-backwpup.php:14-44): streams a full dump of `$wpdb->prefix` tables to the browser (`php://output`), cap `backwpup_jobs_edit`, nonce `backwpupdbdumpdl`.
- **[BUG?]** `edit_form_post_save` (dbdump.php:180-205) reads `$_POST['dbdumpfilecompression']` and `$_POST['dbdumpfile']` which the tab no longer renders → saving the DB tab sets `dbdumpfile` to `''` (dump becomes `.sql`) and emits notices. The JS `page_edit_jobtype_dbdump.js` still posts AJAX `backwpup_jobtype_dbdump` whose handler is the empty base `edit_ajax()`.
- `dbdumpwpony` hidden field is ignored.

### 3.2 FILE (inc/class-jobtype-file.php)
`job_run` (L258-421), 8 substeps, each followed by `do_restart_time()`:
0. root (`ABSPATH` or its parent if `backupabsfolderup`) if `backuproot`, 1. `WP_CONTENT_DIR`, 2. `WP_PLUGIN_DIR`, 3. `get_theme_root()`, 4. uploads (`BackWPup_File::get_upload_dir()`: on multisite `UPLOADBLOGSDIR` or `wp-content/uploads/sites/` or `uploads/`; class-file.php:14-32), 5. `dirinclude` folders, 6. dedupe folder list (rewrite file), 7. special files.
- For each root: `get_exclude_dirs()` (L486-507) excludes the other WP roots nested inside it (content/plugins/themes/uploads) so each is only walked under its own toggle; plus `{root}{dir}/` for each `*excludedirs` entry.
- Recursive walk `get_folder_list()` (L432-480): adds each folder (not files) to the folder list file; skips dirs matching `exclude_from_backup` substrings, excluded dirs, dirs containing `.donotbackup`, unreadable dirs (warning). `BackWPup_Directory` (inc/class-directory.php) skips auto-excluded plugin cache folders (filters `backwpup_exclusion_plugins_folders`, `backwpup_exclusion_plugins_cache_folders`, fed by inc/ThirdParty/* for Autoptimize, Breeze, Hummingbird, SG Optimizer, W3TC, WP-Optimize, WP Rocket, WP Super Cache, WP Fastest Cache).
- Files are enumerated later, per folder, at archive time (`get_files_in_folder`, class-job.php:2112-2197): skip dot/dirs, exclusion substrings, thumbnails (if option), symlinks (warning "Link not following"), unreadable, size >2 GiB; returns realpaths.
- Special files: `wp-config.php` from ABSPATH, or from `dirname(ABSPATH)` if readable and that dir has no `wp-settings.php` (open_basedir aware); `.htaccess nginx.conf .htpasswd robots.txt favicon.ico Web.config` only when `backuproot` is off. Added to `additional_files_to_backup` → stored at archive root under `basename` (class-job.php:1810).
- Output: folder list file + extra-file list (no archive yet).

### 3.3 DBCHECK (inc/class-jobtype-dbcheck.php)
- No file; runs after destinations. For each table (WP-prefix only if `dbcheckwponly`), skip views and non MyISAM/InnoDB: `CHECK TABLE t MEDIUM`; result `ok` (debug log), `warning` (E_USER_WARNING) else error; if `dbcheckrepair` and not ok and MyISAM: `REPAIR TABLE t EXTENDED`. Progress in `steps_data['DONETABLE']`. No restart calls inside.

### 3.4 Other types
None registered beyond these three; `skip_removed_step` handles stale plans (e.g. XML export/WPPLUGIN/OPTIMIZE from upstream).

---

## 4. Archive creation

- Class: **`BackWPup_Create_Archive`** (inc/class-create-archive.php, 978 lines). `vendor/inpsyde/backwpup-archiver` is NOT used for creation (only for restore extraction, §6).
- Method chosen by extension (L76-198): `.tar.gz`/`.tar.bz2` → `TarGz` (gz), `.tar` → `Tar`, `.zip` → `ZipArchive` (fallback `PclZip` from `wp-admin/includes/class-pclzip.php` if no ZipArchive), `.gz`/`.bz2` → single-file `gz` (used for log compression). Files opened in **append** mode (`ab`) so the archive survives restarts.
- Tar writer is hand-written (L592-708, 711-790, 853-895): ustar headers (`a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12`), names >100 chars split into prefix (≤155) + name (warning if still too long; no GNU long-name/PAX support), owner/group via posix, content in 512-byte blocks buffered to 4 MiB writes. End-of-archive (1024 zero bytes) written only in `close()` (L244-262).
- TarGz: every buffered write is `gzencode()`d separately ⇒ the `.tar.gz` is a **concatenation of gzip members** (valid, but some tools/`PharData` may only read the first member) **[UNCERTAIN about PharData]**.
- Zip: small files (<2 MiB) via `addFromString`, others `addFile`; archive closed/reopened every ~20 "file units" to flush file handles (L387-416); re-adding an existing name with same size is skipped, different size is deleted then re-added — this makes zip resume idempotent. Empty dirs via `addEmptyDir`. PclZip batches 100 files.
- `add_empty_folder` for folders without files (tar typeflag 5).
- Engine side (`create_archive()`, class-job.php:1756-1938): `substeps_todo = #folders + 1`. If fresh (no `on_folder`/`on_file`) delete existing archive. Substep 0: add `additional_files_to_backup` (DB dump, manifest.json, backwpup_readme.txt, special files) at archive root. Then for each folder (sorted): skip folders until the saved `on_folder` is reached, list files, skip files until `on_file` is reached, skip any file named like the DB dump (`maybe_sql_dump`), set `on_file`, if `get_restart_time() <= 0` → `do_restart_time(true)` *before* adding (so the file is re-added on resume), else add with in-archive path `get_destination_path_replacement($file)` = path relative to ABSPATH (or its parent) with leading `/` stripped, Windows drive colon removed (L966-982). Any add failure → error, reset position, return false (retry from scratch on next try).
- Tar resume caveat: if PHP dies mid-file (not via the controlled restart) a partial member remains in the appended tar **[BUG?]** (zip handles via the size check).
- After close: size check, logs "Archive size is …", "%d Files with %s in Archive".
- `.tar.bz2` is accepted in code paths but mapped to `.tar.gz` and actually written with gz.

### 4.1 Manifest (`create_manifest()`, class-job.php:2264-2365)
Written to `{temp}manifest.json` (JSON) and `{temp}backwpup_readme.txt`, both added to `additional_files_to_backup` (so they land at archive root). Runs BEFORE `CREATE_ARCHIVE` (steps order). Content:
```json
{
 "blog_info": {"url","wpurl","prefix","description","stylesheet_directory","activate_plugins":[abs paths],
   "activate_theme","admin_email","charset","version"(WP),"backwpup_version","language","name","abspath",
   "uploads": wp_upload_dir() array, "contents":{"basedir","baseurl"}, "plugins":{"basedir","baseurl"},
   "themes":{"basedir","baseurl"}},
 "job_settings": {"dbdumptype","dbdumpfile","dbdumpfilecompression","dbdumpdbcharset","type","destinations",
   "backuptype","archiveformat","dbdumpexclude"},
 "archive": {"extra_files":[basenames], "abspath","uploads","contents","plugins","themes"}   // in-archive prefixes, only for enabled roots
}
```
The restore uses `job_settings.dbdumpfile/dbdumptype/dbdumpfilecompression` to find the dump (vendor/.../Manifest/ManifestFile.php:61-90), `dbdumpdbcharset`/dump header for charset, `blog_info.url` for migration.

---

## 5. Encryption

### 5.1 ENCRYPT_ARCHIVE (class-job.php:1947-2073 + src/Infrastructure/Security/EncryptionStream.php)
- Algorithm: AES-256-CBC (phpseclib3 `AES('CBC')`, continuous buffer, padding disabled; manual PKCS#7-style padding added only when a written chunk is not a multiple of 16) (EncryptionStream.php:58-113, 172-183).
- Key: symmetric = `pack('H*', backwpup_cfg_encryptionkey)` (32 bytes); asymmetric = random 32-byte AES key encrypted with RSA public key `backwpup_cfg_publickey` (phpseclib3 `PublicKeyLoader`, default RSA padding of phpseclib3 = OAEP **[UNCERTAIN: hash params]**).
- IV: 16 random bytes per archive (`phpseclib3\Crypt\Random::string(16)`).
- File format v2 (EncryptionStream.php:115-167):
  - symmetric: `"BACKWPUP"` (8) + `0x02` (version) + `0x01` (type) + IV(16) + ciphertext
  - asymmetric: `"BACKWPUP"` + `0x02` + `0x02` + uint16 BE key length + RSA(AES key) + IV(16) + ciphertext
  - legacy v1 (read-only in decrypter): first byte `0x00` (sym, null IV) or `0x01` (RSA, 1-byte length).
- Processing: 128 KiB blocks from the archive to `{archive}.encrypted` (append), resumable: `substeps_done` = input offset, `OutFilePos` = output offset, `key` and `aesIv` persisted in `steps_data` (i.e. in the running file, in clear) (L1954-1979, 2029-2045). At end the plain archive is deleted and the encrypted file renamed to the original name (same extension) (L2055-2067).
- **[BUG?]** On resume a new `EncryptionStream` is built with the *original IV* instead of the last ciphertext block, so the first 16 bytes of each resumed segment will decrypt incorrectly (CBC chain broken). Inherited from upstream (file unchanged since first commit). Also after `do_restart_time(true)` returns without exiting (e.g. when ENCRYPT is the second-to-last step, `do_restart` refuses) the loop continues on closed streams.
- **[BUG?]** Decrypter `stripPadding` (vendor/inpsyde/backwpup-restore-shared/src/Api/Module/Decryption/Decrypter.php, `stripPadding`) reads the last byte as padding length even when the encryptor added no padding; if the last plaintext byte is `0x00` it does `substr($p,0,-0)` → empties the whole last ≤128 KiB packet. Tar files always end with zero bytes and are 512-aligned (never padded) ⇒ encrypted `.tar` restores lose their tail. Verify with a test before reproducing the format.
- Enabling: only via job option `archiveencryption` + cfg keys — **no UI in the fork** (removed with Pro). WP-CLI `encrypt`/`decrypt` exist (§7).

### 5.2 Decryption on download / restore
- Restore flow: `decompress_upload` throws `DecryptException(STATE_NEED_DECRYPTION_KEY)` if `Decrypter::isEncrypted` (JobController.php:217-236); JS (decrypter.js) shows the key input (views decrypt-key-input.php) and posts AJAX `decrypt` with `decryption_key` (hex for AES, PEM private key for RSA) and optional `encrypted_file_path` → `Decrypter::decrypt()` writes `{file}.decrypted`, validates (zip via archiver `isValid`, tar/tar.gz/tar.bz2 via `Archive_Tar::listContent`, mime via `MimeTypeExtractor`), replaces the original.
- Backups page download: `backup-downloader.js` has decrypt hooks but `decrypter.js` is not enqueued on that page (class-page-backups.php admin_print_scripts) → no decrypt-on-download in the fork; the encrypted file is downloaded as-is **[UNCERTAIN, from static reading]**.

### 5.3 WP-CLI encrypt/decrypt — see §7.

### 5.4 Settings secrets encryption (not archive): `BackWPup_Encryption` (inc/class-encryption.php)
- Prefix `$BackWPup$` + cipher prefix (`OSSL$` OpenSSL or fallback) + key type (`$0` if custom key constant `BACKWPUP_ENC_KEY`, else empty) + base64(nonce+ciphertext).
- OpenSSL: key = `md5(BACKWPUP_ENC_KEY or DB_NAME.DB_USER.DB_PASSWORD)` (32 hex chars used as raw key), cipher preferred `AES-256-CTR|AES-128-CTR|AES-192-CTR` else first available (class-encryption-openssl.php:39-154) — case-sensitive match against `openssl_get_cipher_methods()` may miss lowercase names **[UNCERTAIN]**. Fallback: additive char "cipher" (class-encryption-fallback.php:54-66). Re-encrypts with best cipher on `encrypt()` of already-encrypted strings.
- Changing DB credentials makes stored secrets undecryptable unless `BACKWPUP_ENC_KEY` is defined.

---

## 6. Restore

### 6.1 Wiring
- `BackWPup_Admin::init()` creates `Inpsyde\BackWPup\Infrastructure\Restore\Restore` and calls `set_hooks()->init()` (class-admin.php:692-695):
  - `init()` requires `src/Infrastructure/Restore/commons.php` (not autoloaded) (Restore.php:193-202). Only in admin → `restore_container()` is undefined in WP-CLI (see §7 bug).
  - `admin_init` → `ajax_handler()`: on **every** admin AJAX request calls `restore_boot()` (which does `session_start()`, creates `{uploads}/backwpup-restore`, registers the restore error & exception handlers) and registers the restore AJAX hooks (Restore.php:105-113) **[BUG?: side effects on all admin-ajax calls]**.
  - `admin_head` → `localize_scripts()` (prints `backwpupRestoreLocalized` from `vendor/.../inc/localize-restore-api.php`).
  - `backwpup_page_restore` (fired by `BackWPup_Page_Restore::load` on the page `load-` hook) → `boot()` (shows session notifications) and `handle_restore_log_download_request()` (`?action=download_restore_log` → zips restore log via PclZip and streams it; cap `backwpup_restore`, nonce from `LogDownloader\View`).
- DI: Pimple container in commons.php:60-264: `project_root`=ABSPATH, `project_temp`=`{uploads}/backwpup-restore`, Monolog logger → `restore.log` (INFO), `Registry` persisted to `restore.dat` (PHP serialize), Decompressor(+State/StateUpdater), ErrorHandler, ExceptionHandler, JobController, LanguageController, DecryptController, BackupUpload, DatabaseTypeFactory(mysqli → MysqliDatabaseType), ImportFileFactory(sql → SqlFileImport), ImportModel, RestoreFiles, ManifestFile, Session(`$_SESSION`), Decrypter(archiver Factory), EventSource, AjaxHandler, archiver Factory/Extractor, LevelExtractorFactory.
- Registry fields (Registry.php:20-50): db creds, `dbdumpfile`, `dbdumppos`, `dbdumpsize`, `locale`, `restore_strategy` (`'complete restore'|'db only restore'`), `project_root/temp`, `uploaded_file`, `extract_folder` (`…/extract`), `uploads_folder`, `decompression_state`, `manifest_file`, `extra_files` (blacklist), `restore_list`, `restore_file_start_from`, `restore_finished` (map; finished when 2 jobs for complete, 1 for db-only, then registry reset), `service_name`, `job_id`, `old_url`, `new_url`.

### 6.2 AJAX endpoints (vendor/inpsyde/backwpup-restore-shared/src/AjaxHandler.php)
Registered as `wp_ajax_{hook}` for: `download`, `decompress_upload`, `decrypt`, `get_strategy`, `switch_language`, `save_strategy`, `db_test`, `restore_db`, `restore_dir`, `upload`, `fetch_url`, `save_migration` (L41-54, 117-131) — generic names that may collide with other plugins **[BUG?]**.
- `verify_request()`: silently ignore if no `backwpup_action_nonce`; else `check_ajax_referer('backwpup_action_nonce','backwpup_action_nonce')` + `current_user_can('backwpup_restore')` (L446-456).
- Dispatch: params `controller` (default `Job`) and `action` → `{action}_action` method on AjaxHandler or JobController; `language` → LanguageController; `decrypt` → DecryptController (L136-174, 490-509).
- Responses: `wp_send_json_success({message})`, or SSE (`context=event_source`: `event: message|log`, `data: {state:'progress'|'done'|'error', message}`) (L463-485, 516-557). On non-recoverable exceptions the registry is reset.

| action | method | does |
|---|---|---|
| `upload` | JobController::upload_action (L153-159) → BackupUpload::run (plupload chunked `$_FILES`, chunk/chunks params, writes into `…/uploads/`) | sets `registry.uploaded_file` |
| `download` (SSE) | AjaxHandler::download_action (L343-366) → JobController::download_action (L166-207): uses plugin's `BackWPup_Destination_Downloader_Factory` to pull `source_file_path` from `service`/`jobid` into `local_file_path` by 2 MiB ranges | for restore-from-storage |
| `decompress_upload` (SSE) | decompress_upload_action (JobController L217-236): optional `file_path`; refuse `.bz2`; if encrypted → need key; `Decompressor::run()` | extracts to `extract/`, sets `manifest_file`; requires manifest.json ("only backups made using BackWPup can be restored") |
| `decrypt` | AjaxHandler::handle_decrypt (L222-252) | §5.2 |
| `save_strategy` / `get_strategy` | registry.restore_strategy | |
| `db_test` | db_test_action (JobController L250-303): JSON `db_settings {dbhost,dbname,dbuser,dbpassword,dbcharset}`; resolves dump file from manifest; blacklists it from file restore; connects via mysqli; **rewrites `wp-config.php` inside the extract folder** with the given creds (RestoreFiles::rewriteConfig) | |
| `fetch_url` / `save_migration` | manifest `blog_info.url`; set old/new URL (validated) | migration step disabled in fork UI (TemplateLoader.php:274-278) but endpoint still live |
| `restore_dir` (SSE) | RestoreFiles::restore (L73-127): copies every file from `extract/` (dir by dir via `restore_list`) to ABSPATH, ignoring manifest.json, readme, dump, `extra_files`; then `finish_job('file_restore')` | |
| `restore_db` (SSE) | ImportModel::import (L80-191): `SET sql_mode=''`, `FOREIGN_KEY_CHECKS=0`, stream queries from the dump (SqlFileImport; .gz supported, position saved in registry after each query → resumable on re-request), optional URL search/replace inside queries incl. serialized-length fixing (L193-420), then re-login current user (`wp_set_auth_cookie` + `wp_login`), refresh destination file list | |
| `switch_language` | LanguageController | |

- Decompressor (src/Api/Module/Decompress/Decompressor.php): supports `zip`, `tar`, `gz` (tar.gz) (L43-47); zip via archiver `Extractor::extractByOffset` (ZipArchive or PclZip fallback), tar via `Archive_Tar` `listContent()` then `extractList([name])` one entry at a time (O(n²) scans) with progress state saved in registry (resumable by index) (L284-375). No time-budget logic: relies on SSE request staying alive.

### 6.3 Admin UI flow (inc/class-page-restore.php, src/Infrastructure/Restore/TemplateLoader.php, views/restore/*, JS)
- Page `backwpuprestore` (cap `backwpup_restore`) renders `views/restore/index.php` → `main.php` with actions `backwpup_restore_before_main_content` (step `_top` view), `backwpup_restore_main_content` (step `_action` view), dashboard (restore-log download link). Step = `?step=N` (TemplateLoader.php:90-100, 205-218).
- Steps (views/restore/steps): 1 Upload (plupload drag-drop + decrypt key input), 2 Strategy ("Full Restore" / "Database Only"), 3 Database settings (prefilled, edit, test, continue), 4 Migration (disabled: `migrate_allowed=false`), 5 Restore (button: complete → restore_dir then restore_db; db-only → restore_db), 6 Success + error report from restore.log (`LevelExtractor`).
- JS: `assets/js/restore.js` (114 lines, orchestrator) + vendor `restore-shared/resources/js/{controller,decompress,download,strategy,database,database-restore,files-restore,decrypter,migrate,restore-functions}.js` + `backwpup-shared/resources/js/{functions,states}.js` + `assets/js/vendor/url.min.js` + WP `plupload`, `underscore`, `jquery`. Uses `EventSource` for long operations.
- From the Backups list a "Restore" action exists only if a destination returns `restoreurl` in its file list (class-page-backups.php:297-309) — **no destination in the fork sets `restoreurl`**, so restore is upload-only in practice; the step-1 `trigger_download`/`restore_file`/`service`/`jobid` URL params (download.js) are dormant.

### 6.4 Size of code to rewrite (lines incl. comments)
| part | lines |
|---|---|
| src/Infrastructure/Restore (commons, Restore, TemplateLoader, Notificator, LogDownloader/*) | 1 461 |
| vendor/inpsyde/backwpup-restore-shared PHP (src/inc/views) | 6 562 |
| restore-shared JS (non-minified) | 1 874 |
| backwpup-archiver src (zip extract, Factory, FallBackZip) | 1 266 (+ pclzip.lib.php 5 689, replaceable by ZipArchive) |
| backwpup-shared (MimeTypeExtractor + functions/states JS) | 229 + 105 |
| views/restore + assets/js/restore.js + class-page-restore.php | 454 + 114 + 245 |
| **Total own logic to reimplement** | **≈ 12 300 lines** (≈ 9 700 PHP, ≈ 2 100 JS), excluding Archive_Tar (2 530) and PclZip |

A React rewrite can collapse this substantially (realistic target 2–3k PHP + a React wizard), since much is DI/exception/translation boilerplate.

---

## 7. WP-CLI (`wp backwpup …`, inc/class-wp-cli.php)
| command | args | behaviour |
|---|---|---|
| `start <jobid>` | positional id, or deprecated `--jobid=N` | errors if running file exists / no id / unknown id; `BackWPup_Job::start_cli()` (sync, no restarts) (L21-47) |
| `abort` | — | `user_abort()` if running file exists (L52-61) |
| `jobs` | — | table `Job ID`, `Name` (L66-91) |
| `working` | — | table JobID, Name, Warnings, Errors, On Step, Done (`step% / substep%`) + last message (L99-136) |
| `decrypt <file>` | `--key=<hex or path to private key file>`; default symmetric key from `backwpup_cfg_encryptionkey` | uses restore container `decrypter` (L150-188). **[BUG?]** `restore_container()` lives in commons.php which is only required in admin → likely fatal under WP-CLI |
| `encrypt <file>` | `--key=<hex>` (symmetric) or `--key=<path to public key file>` (asymmetric); default from cfg (asymmetric unless cfg says symmetric) | writes `{file}.encrypted` then replaces original (L202-279). Doc mentions `-keyfile`, not implemented |

---

## 8. Multisite
- `Network: true` plugin header; menus on `network_admin_menu` when multisite (class-admin.php:704-708); all URLs use `network_admin_url`.
- All settings and jobs are **site options** (network-wide): `get/update_site_option` everywhere (on single site they map to normal options).
- Guards: bootstrap L41 (see §0 caveat); `BackWPup_Cron::run` returns unless `is_main_site()` (class-cron.php:13). WP-Cron events are scheduled from whichever context activation runs in **[UNCERTAIN: on subsites the cron array is per-blog]**.
- Object-cache purge key `{network_id}:backwpup_jobs` in `site-options` (class-option.php:90-93).
- Uploads root for FILE type is `wp-content/uploads/sites/` (all sites) on multisite (class-file.php:16-28); DB dump default excludes only tables not starting with base `$wpdb->prefix` (so all `wp_N_` subsite tables are included).
- Uninstall deletes `sitemeta LIKE '%backwpup_%'` and removes roles on every site (uninstall.php:15-41).
- Restore `Notificator` uses `network_admin_notices` in network admin (Notificator.php:55).
- Role profile field shown only to super admins or `backwpup_admin` (class-admin.php:579-690).

---

## 9. Capabilities & roles (inc/class-install.php:40-104)
Caps: `backwpup` (general/dashboard/menu), `backwpup_jobs`, `backwpup_jobs_edit`, `backwpup_jobs_start`, `backwpup_backups`, `backwpup_backups_download`, `backwpup_backups_delete`, `backwpup_logs`, `backwpup_logs_delete`, `backwpup_settings`, `backwpup_restore`. All granted to `administrator`.

Roles: `backwpup_admin` (all caps + read), `backwpup_check` (backwpup, jobs, backups, logs — read-only), `backwpup_helper` (backwpup, jobs, jobs_start, backups, backups_download, backups_delete, logs, logs_delete). A user-profile select lets super admins/backwpup_admin add one backwpup_* role to a user (class-admin.php:579-690).

Where checked:
| cap | checks |
|---|---|
| `backwpup` | top menu + Dashboard (class-admin.php:212-224); `admin-post.php?action=backwpup` gate (class-admin.php:484); jobs list `ajax_user_can` (class-page-jobs.php:29-31); admin bar root (class-adminbar.php:44) |
| `backwpup_jobs` | Jobs submenu; dashboard "next jobs" box (class-page-backwpup.php:121); admin bar |
| `backwpup_jobs_edit` | Add/Edit job page & save (class-page-editjob.php:40, 834; class-admin.php submenu), copy/delete job (class-page-jobs.php:381,396), edit/copy/delete row actions (L172), S3/SFTP/kDrive save & AJAX (class-destination-s3.php:414, -sftp.php:151, -kdrive.php:112), dashboard one-click DB download (class-page-backwpup.php:18) |
| `backwpup_jobs_start` | run now (class-page-jobs.php:432), abort (L504), running-job panel (L661), `ajax_working` (L832), admin bar run/abort, dashboard |
| `backwpup_backups` | Backups submenu, list `ajax_user_can` (class-page-backups.php:26-28) |
| `backwpup_backups_download` | download actions (class-page-backups.php:283, 393), `BackWPup_Destination_Downloader::CAPABILITY` (class-destination-downloader.php:169), `BackWPup_Destinations::CAPABILITY`, jobs "Download last backup" (class-page-jobs.php:321) |
| `backwpup_backups_delete` | delete row action and bulk delete (class-page-backups.php:279, 350) |
| `backwpup_logs` | Logs submenu, view log AJAX (class-page-logs.php:443), log download (L327), last-log links |
| `backwpup_logs_delete` | delete log (class-page-logs.php:225, 298) |
| `backwpup_settings` | Settings submenu, `save_post_form` (class-page-settings.php:326) |
| `backwpup_restore` | Restore submenu (class-admin.php:446), all restore AJAX (`verify_request`), restore log download (Restore.php:128, LogDownloader/View.php:97), Restore row action |

---

## 10. Admin pages, actions and handlers

Menu (class-admin.php:210-466), slug → cap → class:
- `backwpup` Dashboard → `backwpup` → `BackWPup_Page_BackWPup` (inc/class-page-backwpup.php): static help text; "First steps" box; "One click backup" → `?page=backwpup&action=dbdumpdl&_wpnonce` (DB dump download); "Next scheduled jobs" table (running job with runtime + Abort link; else next wpcron run times; edit links); "Last logs" table (5 latest: time, job, result OK/errors/warnings, from `read_logheader`).
- `backwpupjobs` Jobs → `backwpup_jobs` → `BackWPup_Page_Jobs` (WP_List_Table, inc/class-page-jobs.php):
  - Columns: cb, Job Name (+ row actions Edit/Copy/Delete/Run now/Last log), Type, Destinations, Next Run (wpcron time / "By cron-job.org" / "External link" / "Inactive"), Last Run (date, runtime, Download last backup, Log). Sortable: jobname, type, dest, next, last.
  - Bulk action: delete (nonce `bulk-jobs`; also clears cron hook and cron-job.org job) (L380-393).
  - GET actions on `load`: `delete`, `copy` (nonce `copy-job_{id}`; new id=max+1, "Copy of …", activetype cleared, archivename id replaced, skips logfile/lastbackupdownloadurl/lastruntime/lastrun/cronjoborgid) (L395-427), `runnow` (§2.1), `abort` (nonce `abort-job`) (L503-514); default → `do_action('backwpup_page_jobs_load', $action)`.
  - Running job panel (L661-700): name, warnings/errors, two progress bars (step %, substep %), current step name, last message, full log in thickbox, Abort button; polled by inline JS via AJAX `backwpup_working`.
  - "Last download" via backup-downloader thickbox when URL points to `backwpupbackups` (L343-372).
- `backwpupeditjob` Add/Edit job → `backwpup_jobs_edit` → `BackWPup_Page_Editjob` (inc/class-page-editjob.php): tabs `job` (General: name, job types checkboxes, archive name with `%hash%` preview, archive format zip/tar/tar.gz, destinations checkboxes, log mail to/from/errors only), `cron` (Schedule: manual / WP-Cron / external link (shows runext URL) / cron-job.org (API key field); basic or advanced cron editor with live text via AJAX `backwpup_cron_text`), `jobtype-dbdump|file|dbcheck`, `dest-folder|ftp|sftp|kdrive|s3`. Tabs hidden client-side when type/destination unchecked. Form posts to `admin-post.php` `action=backwpup`, `page=backwpupeditjob`, `tab`, `nexttab`, `anchor`, `jobid`, nonce `backwpupeditjob_page` + `backwpupajaxnonce` (`backwpup_ajax_nonce`). After save, message with "Run now" link; schedules wpcron event & syncs cron-job.org (L130-206). Destination `edit_auth($jobid)` hook on load (L18-31).
- `backwpuplogs` Logs → `backwpup_logs` → `BackWPup_Page_Logs` (inc/class-page-logs.php): list of `backwpup_log_*.html[.gz]` in log folder, columns Time, Job (thickbox view), Status (errors/warnings counts), Type, Size (backup size), Runtime; row actions View (AJAX `backwpup_view_log`, nonce `view-log_{name}`), Delete (nonce `bulk-logs`), Download (`action=download&file=`, nonce `download_backwpup_logs`); bulk delete; screen option `backwpuplogs_per_page`.
- `backwpupbackups` Backups → `backwpup_backups` → `BackWPup_Page_Backups` (inc/class-page-backups.php): destination selector (`{jobid}_{DEST}` pairs that have files, built from `file_get_list` = site transient caches), columns Time, File (+ Delete / Download / Restore actions), Folder, Size; bulk delete (nonce `bulk-backups`, `backupfiles[]`, `jobdest-top`) → `$dest->file_delete`; Download → thickbox + SSE `admin-ajax.php?action=download_backup_file&destination&jobid&file&local_file&backwpup_action_nonce` streams the remote file into temp by 2 MiB chunks (`BackWPup_Destination_Downloader::download_by_chunks`, class-destination-downloader.php), then browser hits `page=backwpupbackups&jobid=…&file=…&local_file=…&_wpnonce` which streams and deletes the temp copy (L389-416); legacy `action=download{dest}` fallback to `$dest->file_download`.
- `backwpupsettings` Settings → `backwpup_settings` → `BackWPup_Page_Settings` (inc/class-page-settings.php): tabs Jobs (step retry, max execution time, external start key, reduce server load (jobwaittimems 0/10000/30000/90000 µs), keep-alive output, Windows IIS compat), Logs (folder, maxlogs, gzlogs, loglevel), Network (loopback auth method none/basic/user/query_arg + fields), Information (environment table from `get_information()`, copy-to-clipboard debug info). Buttons "Save" and "Reset all settings to default". Hooks: `backwpup_page_settings_tab`, `backwpup_page_settings_save`.
- `backwpuprestore` Restore → `backwpup_restore` → §6.3.
- Admin bar (inc/class-adminbar.php): root, "Now Running" + "Abort!" (links to `page=backwpup&action=abort` — **not handled by the dashboard page, broken** [BUG?]), Jobs, Add new, Logs, Backups, per-job edit + Run Now.
- Help tabs (inc/class-help.php), footer texts.

### 10.1 Complete handler list
| hook | handler | auth |
|---|---|---|
| `admin_post_backwpup` | `BackWPup_Admin::save_post_form` (class-admin.php:469-524) — routes `page=backwpupeditjob` → `BackWPup_Page_Editjob::save_post_form($tab,$jobid)`, `page=backwpupsettings` → settings save (`backwpupinformation` allowed but no-op) | nonce `{page}_page`, cap `backwpup` (+ per-page cap) |
| `wp_ajax_backwpup_working` | `BackWPup_Page_Jobs::ajax_working` (class-page-jobs.php:828-934) → JSON `{log_pos, log_text, warning_count, error_count, running_time, step_percent, on_step, last_msg, last_error_msg, sub_step_percent, restart_url, job_done}`; params `logfile`, `logpos` | nonce `backwpupworking_ajax_nonce`, `backwpup_jobs_start` |
| `wp_ajax_backwpup_cron_text` | `BackWPup_Page_Editjob::ajax_cron_text` (L828+) → HTML text of next runs for posted cron fields | `backwpup_jobs_edit` + nonce `backwpup_ajax_nonce` |
| `wp_ajax_backwpup_view_log` | `BackWPup_Page_Logs::ajax_view_log` (L441-462) → raw log HTML | `backwpup_logs` + nonce `view-log_{log}` |
| `wp_ajax_download_backup_file` | `BackWPup_Destination_Downloader::download_by_ajax` → SSE; inner `BackWpup_Download_Handler` verifies nonce `backwpup_action_nonce` & `backwpup_backups_download` (class-destinations.php:80-118, class-download-handler.php) | |
| `wp_ajax_backwpup_jobtype_{dbdump,file,dbcheck}` | `$jobtype->edit_ajax()` (all empty) | — |
| `wp_ajax_backwpup_dest_{folder,ftp,sftp,kdrive,s3}` | `$dest->edit_ajax()` (only S3 implements: bucket list for given creds, nonce `backwpup_ajax_nonce`, cap `backwpup_jobs_edit`, class-destination-s3.php:412-440) | |
| `wp_ajax_{download,decompress_upload,decrypt,get_strategy,switch_language,save_strategy,db_test,restore_db,restore_dir,upload,fetch_url,save_migration}` | restore AjaxHandler (§6.2) | nonce `backwpup_action_nonce` + `backwpup_restore` |
| GET `load-*` actions | jobs: delete/copy/runnow/abort; logs: delete/download; backups: delete/download; dashboard: dbdumpdl; restore: download_restore_log | see above |
| wp-cron.php `?backwpup_run=` | `BackWPup_Cron::cron_active` | §2.1 |
| REST routes | **none** (`register_rest_route` not used) | |
| profile hooks | `show_user_profile`/`edit_user_profile`/`profile_update` role select | super admin / `backwpup_admin` |

Notable plugin hooks for compatibility: filters `backwpup_file_exclude`, `backwpup_{root,content,plugins,themes,upload}_exclude_dirs`, `backwpup_dir_include`, `backwpup_exclusion_plugins_folders`, `backwpup_exclusion_plugins_cache_folders`, `backwpup_generate_dump_filename`, `backwpup_gz_logs`, `backwpup_protect_folders`, `backwpup_show_folder_size`, `backwpup_is_in_admin_bar`, `backwpup_cacert_bundle`, `backwpup_register_destination`, `backwpup_job_types`, `backwpup_job_signals_to_handel`, `backwpup_admin_pages`, `backwpup_page_jobs_actions`, `backwpup_page_jobs_get_bulk_actions`, `backwpup_page_settings_tab`; actions `backwpup_admin_messages`, `backwpup_page_jobs_load`, `backwpup_page_settings_save`, `backwpup_page_restore`, restore view actions.

---

## 11. Vendor packages: runtime use and replaceability

| package | used by (non-vendor code) | verdict |
|---|---|---|
| `phpseclib/phpseclib` 3.0.39 (3 MB) | SFTP client (inc/class-oueb-sftp-client.php), archive encryption (EncryptionStream AES/RSA; class-job.php:1963,1970), WP-CLI encrypt, restore Decrypter | Keep for SFTP (no built-in alternative short of ext-ssh2). AES-256-CBC/RSA-OAEP could move to `ext-openssl` (`openssl_encrypt` with `OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING`, `openssl_public_encrypt` with OAEP — but phpseclib3 OAEP defaults to SHA-256/MGF1-SHA256 whereas openssl_public_encrypt OAEP is SHA-1 → format-compatibility issue **[UNCERTAIN, verify phpseclib defaults]**) |
| `paragonie/constant_time_encoding`, `paragonie/random_compat` | phpseclib dependency / polyfill (no-op on PHP ≥7) | drop random_compat; constant_time only with phpseclib |
| `guzzlehttp/psr7` + `psr/http-message` + `ralouphie/getallheaders` | `Utils::streamFor/tryFopen` + `StreamDecoratorTrait` in encryption (class-job.php:1993-2019, EncryptionStream.php:7) and WP-CLI encrypt; dead Http layer | Drop: plain `fopen`/`fwrite` suffice |
| `psr/http-client`, `psr/http-factory` | only `src/Infrastructure/Http/*` (WpHttpClient etc.) — **dead code** (no reference from inc/ or vendor) | Drop |
| `webmozart/assert` | dead Http layer; restore-shared (AjaxHandler, Api) | Drop with rewrite |
| `symfony/options-resolver` | `BackWPup_MySQLDump::configureOptions` (class-mysqldump.php:153-217); dead WpHttpClient | Drop (simple array defaults) |
| `symfony/polyfill-php80`, `symfony/polyfill-mbstring`, `symfony/deprecation-contracts` | transitive; PHP ≥8.1 required | Drop (mbstring ext usually present) |
| `christian-riesen/base32` | `BackWPup_Option::get_generated_hash/decode_hash` (archive-name hash) | Replace with 10-line base32 (must stay compatible with existing archive names if old archives are to be recognised) |
| `pimple/pimple` | restore DI container (commons.php), LogDownloader, TemplateLoader | Drop |
| `monolog/monolog` + `psr/log` | restore logger (commons.php:85-90) and restore-shared / archiver (`LoggerInterface`) | Replace by simple file logger |
| `psr/container` | Pimple dependency | Drop |
| `pear/archive_tar` (+ `pear/console_getopt`, `pear/pear-core-minimal`, `pear/pear_exception`) | restore tar/tar.gz extraction (Decompressor) and post-decrypt validation (Decrypter) | Replace with `PharData` (needs ext-phar; beware multi-member gzip from our writer — test) or a small own tar reader (we already own a tar writer) |
| `inpsyde/backwpup-archiver` (+ bundled `pclzip.lib.php`, `inpsyde/assert`) | restore zip extraction (`Extractor::extractByOffset`), Decrypter zip validation | Replace with `ZipArchive` |
| `inpsyde/backwpup-restore-shared` | entire restore backend + JS + `wp backwpup decrypt` | Rewrite (§6.4) |
| `inpsyde/backwpup-shared` | `MimeTypeExtractor` (class-download-file.php, class-destination-s3.php, Decrypter) + `functions.js`/`states.js` | Replace with `wp_check_filetype`/`finfo`/`mime_content_type` |
| `inpsyde/assert` | archiver | Drop |
| WordPress-bundled `PclZip` | `BackWPup_Create_Archive` fallback (class-create-archive.php:152-162), restore log zip (LogDownloader/DownloaderFactory.php:88-127) | Use ZipArchive only (or gz the log) |

Built-ins that can replace things: `ZipArchive` (zip create/extract), `PharData` (tar/tar.gz read; writing large tars incrementally across restarts is easier with our own writer), `mysqli` (already used for dump; restore uses mysqli too), `ext-openssl` (AES-CBC/CTR, RSA), `random_bytes`, `gzopen`/`compress.zlib://` streams.

---

## 12. Other noteworthy defects / leftovers to not reproduce
1. `do_restart` loopback depends on `sslverify=false` and `timeout 0.01`; on hosts blocking loopbacks only the 60 s `restart` cron (which itself needs WP-Cron traffic) keeps jobs alive.
2. `BackWPup_Job::start_http` random usleep is the only anti-double-start measure.
3. Archive-name date vars use `date()` with WP local timestamp (fine) but cron uses integer `gmt_offset`.
4. Logs folder, temp and backup folder are under `uploads/backwpup/{hash}/…` protected only by `.htaccess`/`index.php`/`Web.config` depending on server software detection (class-file.php:269-300) — nginx gets only an index.php.
5. `backwpup_messages` is a network-wide queue shared by all admins.
6. `restore_boot()` (`session_start`, handlers) runs on every admin-ajax request.
7. Restore AJAX actions use generic, unprefixed `wp_ajax_*` names.
8. `edit_form_post_save` of DBDUMP wipes `dbdumpfile` (§3.1).
9. Encryption resume/padding issues (§5.1); no UI for encryption keys.
10. Admin-bar Abort link targets the dashboard which doesn't handle `abort`.
11. `backwpup_cfg_keepplugindata`, `backwpup_cfg_httpauth*`, `license_*`, sync mode, `wizard_*` hooks, `data_storage`, `src/Infrastructure/Http` and `src/Infrastructure/Xml` (1 152 lines) are dead.
12. WP-CLI `decrypt` likely fatal outside admin (commons.php not loaded).
13. Settings form lacks the checkboxes `showadminbarmenu`, `showfoldersize`, `protectfolders`, `gzlogs`, `keepplugindata` but `save_post_form` still writes them from `$_POST` → any settings save turns them all off (class-page-settings.php:374-402, 462).
