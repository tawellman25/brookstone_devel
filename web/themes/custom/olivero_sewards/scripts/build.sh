#!/usr/bin/env bash
#
# Generate and copy Olivero css and fonts to our theme for override.

set -eu

__red=$'\e[1;31m'
__grn=$'\e[1;32m'
__blu=$'\e[0;34m'

_my_sub_theme=${1:-"olivero_sub_theme"}

_DIR="$(cd -P "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

_drupal_core="web/core"
_drupal_core_olivero="web/core/themes/olivero"
_my_sub_theme_path="web/themes/custom/${_my_sub_theme}"

if [ ! -d "${_drupal_core_olivero}" ]; then
  _drupal_core_olivero="$_DIR/../../../../core/themes/olivero"
  if [ ! -d "${_drupal_core_olivero}" ]; then
    echo -e "${__red}[ERROR]\e[0m Can not find Olivero theme, are you at the root of Drupal? Looking for '${_drupal_core_olivero}'"
    exit 1
  fi
fi

if [ ! -d "${_my_sub_theme_path}" ]; then
  _my_sub_theme_path="$_DIR/../../../../themes/custom/${_my_sub_theme}"
  if [ ! -d "${_my_sub_theme_path}" ]; then
    echo -e "${__red}[ERROR]\e[0m Can not find Sub theme, did you create/copy in '${_my_sub_theme_path}'?"
    exit 1
  fi
fi

if [ ! -d "${_drupal_core}" ]; then
  _drupal_core="$_DIR/../../../../../${_drupal_core}"
  if [ ! -d "${_drupal_core}" ]; then
    echo -e "${__red}[ERROR]\e[0m Can not find Drupal core in '${_drupal_core}', please run this script from Drupal root folder."
    exit 1
  fi
fi

_drupal_core="$(
  cd "$(dirname "$_drupal_core")"
  pwd
)/$(basename "$_drupal_core")"
_drupal_core_olivero="$(
  cd "$(dirname "$_drupal_core_olivero")"
  pwd
)/$(basename "$_drupal_core_olivero")"
_my_sub_theme_path="$(
  cd "$(dirname "$_my_sub_theme_path")"
  pwd
)/$(basename "$_my_sub_theme_path")"

if ! command -v npm >/dev/null 2>&1; then
  echo -e "${__red}[ERROR]\e[0m Can not find NPM, please install to use this script."
  exit 1
fi

if [ ! -d "${_drupal_core}/node_modules" ]; then
  echo -e "${__blu}[Notice]\e[0m One time install of Drupal packages with NPM..."
  cd "${_drupal_core}" && npm install
fi

if [ -d "${_drupal_core_olivero}/css.orig/" ]; then
  rm -rf "${_drupal_core_olivero}/css.orig/"
fi

cp -r "${_drupal_core_olivero}/css/" "${_drupal_core_olivero}/css.orig/"
cp -f "${_my_sub_theme_path}/css/variables.pcss.css" "${_drupal_core_olivero}/css/base/variables.pcss.css"

# Build the theme
cd "${_drupal_core}" && npm run build:css

# Copy the result of build.
mv "${_my_sub_theme_path}/css/theme.css" "${_my_sub_theme_path}/theme.css"
mv "${_my_sub_theme_path}/css/variables.pcss.css" "${_my_sub_theme_path}/variables.pcss.css"
rm -rf "${_my_sub_theme_path}/css/"
mkdir -p "${_my_sub_theme_path}/css/"
mv "${_my_sub_theme_path}/theme.css" "${_my_sub_theme_path}/css/theme.css"
mv "${_my_sub_theme_path}/variables.pcss.css" "${_my_sub_theme_path}/css/variables.pcss.css"

cp -r "${_drupal_core_olivero}/css" "${_my_sub_theme_path}/"
cp -r "${_drupal_core_olivero}/fonts" "${_my_sub_theme_path}/"
rm -f "${_my_sub_theme_path}/css/**/*.pcss.css"

# Set back Olivero files.
rm -rf "${_drupal_core_olivero}/css/"
mv "${_drupal_core_olivero}/css.orig/" "${_drupal_core_olivero}/css/"

echo -e "${__grn}[Success]\e[0m Olivero Sub theme built!"
