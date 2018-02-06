# Repertoire as mp3

## Requirements

1. Install youtube-dl python package :

```
sudo curl -L https://yt-dl.org/downloads/latest/youtube-dl -o /usr/local/bin/youtube-dl
sudo chmod a+rx /usr/local/bin/youtube-dl
```

2. Install the vendors :

`composer install`

## Execution

Run the command :

`bin/app`

The `downloads/` folder should be full of mp3s... ;)

## Check file sizes

`ls -1Rs downloads/ | grep '.mp3' | sed -e "s/^ *//" | grep "^[0-9]" | sort -nr | head -n50`
