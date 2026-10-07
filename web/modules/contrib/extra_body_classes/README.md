# Extra body classes

## Contents of this file

- Introduction
- Requirements
- Installation
- Configuration
- Maintainers
- Supporting organization

## Introduction

Extra body classes is a simple module which will provide extra classes on the `<body>` tag. This module will provide below functionality.

- **Timestamp**: This option will provide time stamp as a class `<body>` tag. ex. 20151007
- **Year**: This option will provide year as a class to  `<body>` tag. ex. 2015
- **Month**: This option will provide month as a class to  `<body>` tag. ex. october
- **Day**: This option will provide day as a class to  `<body>` tag. ex. wednesday
- **Current browser platform**: This option will provide current browser platform as a class to  `<body>` tag. ex. mac
- **Current browser name and version**: This option will provide current browser name and version as a class to  `<body>` tag. ex. chrom chrom46
- **Current device**: This option will check whether current device is Desktop or Mobile and implement as a class to `<body>` tag. ex. Desktop
- **Roles**: This option will provide current user roles as a class to  `<body>` tag. ex.authenticated administrator
- **Single Day Event**: This option will provide custom single day event classes to  `<body>` tag. ex. independence-day- note: This class will be removed once single day event is over.
- **Multiple Day Event**: This option will provide custom multiple day event classes to  `<body>` tag. ex. drupal-con-asia- note: This class will be removed once multiple day event is over.
- **Custom Classes Based On Path**: This option will provide custom classes to  `<body>` tag based on path. ex.node/1, about-us


## Requirements

This module requires no modules outside of Drupal core.


## Installation

Install as you would normally install a contributed Drupal module. For further
information, see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).


## Configuration

1. For configuration go to `admin/config/content/extra-body-classes` check options and save
1. Output:  `<body class="20151014 2015 october wednesday [...]>`


## Maintainers

Current maintainers:

- Rahul Baisane (rahulbaisanemca) - https://www.drupal.org/u/rahulbaisanemca

## Supporting organization

- [QED42 Engineering Pvt. Ltd.](https://www.drupal.org/marketplace/qed42)
