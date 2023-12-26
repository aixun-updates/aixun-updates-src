Unofficial changelog of updates for AiXun devices
-------------------------------------------------

This is source code/development repository for https://aixun-updates.github.io/.

Directory `/backend` contains server-side PHP script intended for CLI CRON. This script periodically pulls latest
changelogs and firmware files from manufacturer server, saves these files and processes these files 
for use with `/frontend`.

Directory `/frontend` contains HTML page intended for github-pages. This is mirror of 
https://github.com/aixun-updates/aixun-updates.github.io.
