#!/bin/bash
PROGRESS_FILE=/tmp/jeedom/MerosSync/dependance
if [ ! -z $1 ]; then
	PROGRESS_FILE=$1
fi
touch ${PROGRESS_FILE}
echo 0 > ${PROGRESS_FILE}
echo "Installation des dépendances"
sudo apt-get update
echo 10 > ${PROGRESS_FILE}
echo "Installation libffi-dev"
sudo apt-get install -y libffi-dev
echo 20 > ${PROGRESS_FILE}
echo "Installation python3"
sudo apt-get install -y python3
echo 30 > ${PROGRESS_FILE}
echo "Installation python3-pip"
sudo apt-get install -y python3-pip
echo 40 > ${PROGRESS_FILE}
echo "Installation python3-requests"
sudo apt-get install -y python3-requests
echo 50 > ${PROGRESS_FILE}
echo "Installation python3-setuptools"
sudo apt-get install -y python3-setuptools
echo 60 > ${PROGRESS_FILE}
echo "Installation python3-dev"
sudo apt-get install -y python3-dev
echo 70 > ${PROGRESS_FILE}
echo "Empty cache"
sudo pip3 cache purge
echo "Installation upgrade pip"
sudo pip3 install --upgrade pip
echo 80 > ${PROGRESS_FILE}
echo "Installation upgrade requests"
sudo pip3 install --upgrade requests
echo 90 > ${PROGRESS_FILE}
echo "Installation upgrade merossiot"
BASEDIR=$(dirname "$0")
meross_version=$(head -1 $BASEDIR/meross-iot_version.txt)
sudo python3 -m pip install meross_iot==$meross_version
echo 100 > ${PROGRESS_FILE}
echo "Installation des dépendances terminée !"
rm ${PROGRESS_FILE}
