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

### Examples of configuration files

Here's one I use to download songs from my Trello repertoire board, along with tabs :

```yaml
path_part:
    path: '/home/gnutix/Music/Repertoire'
    priority: -255

sources:
    -
        Extension\Trello\Trello:
            config:
                board_id: MkYHGxzY
                card_properties:
                    - '[desc]'
                    - '[attachments][%index%][url]'
            downloaders:
                -
                    Extension\File\File:
                        config:
                            extensions: '(?:pdf|mp3)'
                -
                    Extension\YouTubeDl\YouTubeDl: ~
```

Here's another I use to download files from a website for which I have a paid account and extracted a list of URLs
(in data.csv) containing the videos :

```yaml
path_part:
    path: '/home/gnutix/Downloads/QLRR'
    priority: -255

sources:
    -
        Extension\CSV\CSV:
            config:
                base_url: 'https://qlrr.fr/6/qlrr/'
                resources:
                    - '/home/gnutix/Downloads/QLRR/data.csv'
            downloaders:
                -
                    Extension\File\File:
                        config:
                            extensions: '(?:pdf|mp4)'
                -
                    Extension\YouTubeDl\YouTubeDl:
                        config:
                            referer: 'https://qlrr.fr/6/'
                            download_files:
                                video: mp4
```

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
