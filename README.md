# Videos Downloader

This script allows to fetch videos from a source (ex. extract YouTube URLs from Trello cards' descriptions) and
download them locally into multiple formats (mp3, mp4).

[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/gnutix/videos-downloader/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/gnutix/videos-downloader/?branch=master)

## Requirements

1. Install youtube-dl python package : https://github.com/rg3/youtube-dl#installation
2. Install the vendors :

`composer install`

## Running the application

Copy the default configuration file and adapt the values as needed:

`cp config/app.yml.dist config/app.yml`

To get a list of options for running the script :

`bin/app --help`

And finally to run the script :

`bin/app`

Congratulations! The `downloads/` folder should be full of mp3 / mp4 files... ;)

## Checking the downloaded files

Display the number of files by extension :

```
find downloads/ -name "*.mp3" | wc -l
find downloads/ -name "*.mp4" | wc -l
```

Display the sum of all files sizes by extension :

```
find downloads/ -name "*.mp3" -exec du -b {} \; | awk '{total+=$1}END{print total}' | numfmt --to=iec-i
find downloads/ -name "*.mp4" -exec du -b {} \; | awk '{total+=$1}END{print total}' | numfmt --to=iec-i
```

List the 30 most heavy files by extension :

```
ls -1Rs downloads/ | grep '.mp3' | sed -e "s/^ *//" | grep "^[0-9]" | sort -nr | head -n30
ls -1Rs downloads/ | grep '.mp4' | sed -e "s/^ *//" | grep "^[0-9]" | sort -nr | head -n30
```
