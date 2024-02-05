# Tiny Compress

Tiny Compress is a simple WordPress plugin, which allows compressing images by using TinyPNG API and cwebp command line tool. It will first compress each image size with TinyPNG and then convert the compressed images into webp files for further optimization. When compressing, a backup is saved, which allows to undo the process later. Use this plugin together with my other plugin, [Remove Image Sizes](https://github.com/maazjes/tiny-compress), for full scale image optimization.

## Installation

Install the dependencies by running:

```bash
sudo apt update
```

and:

```bash
sudo apt install webp
```

After installing the dependencies, you can install the plugin as you normally would.

## Demo

https://github.com/maazjes/tiny-compress/assets/59210742/702dfd64-6a91-4ec4-9c8a-401b3976c53d
