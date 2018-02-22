# Videos Downloader

This script automates and simplify extracting resources (PDFs, audios/video files, ...) from a source (Trello board,
CSV file, ...) and downloading them on your computer.

[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/gnutix/videos-downloader/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/gnutix/videos-downloader/?branch=master)

## Requirements

1. Install youtube-dl python package : https://github.com/rg3/youtube-dl#installation
1. Install Composer : https://getcomposer.org/download/
1. Install the vendors :

```shell
composer install
```

## Running the application

Copy the default configuration file and adapt the values as needed:

```shell
cp config/app.yml.dist config/app.yml
```

To check the configuration files validity, you can run :

```shell
bin/yaml-lint
```

To get a list of options for running the script, run :

```shell
bin/app --help
```

And finally to run the script itself :

```shell
bin/app
```

Congratulations! The `downloads/` folder should be full of files now... ;)

### Some tips for analyzing the downloaded files

Display the number of files by extension :

```shell
export EXTENSION="mp3" # or mp4, pdf, ...
find downloads/ -name "*.${EXTENSION}" | wc -l
```

Display the sum of all files sizes by extension :

```shell
export EXTENSION="mp3" # or mp4, pdf, ...
find downloads/ -name "*.${EXTENSION}" -exec du -b {} \; | awk '{total+=$1}END{print total}' | numfmt --to=iec-i
```

List the 30 most heavy files by extension :

```shell
export EXTENSION="mp3" # or mp4, pdf, ...
ls -1Rs downloads/ | grep '.${EXTENSION}' | sed -e "s/^ *//" | grep "^[0-9]" | sort -nr | head -n30
```
