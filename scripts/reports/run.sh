#!/usr/bin/env bash

set -ex

rm -f output/subscription_list.tex
python subscribers_list.py
cd output
pdflatex subscription_list.tex
