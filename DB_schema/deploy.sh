#!/bin/bash

readonly path=$(readlink -m "$1")

if [ ! -z "$path" ]; then

	if [ ! -d "$path" ]; then
		echo 'Invalid schema directory path'
		exit
	fi

	cd "$path"
fi

readonly initPath="$path/DB.sql";

if [ ! -r "$initPath" ]; then
	echo "$initPath is not exist"
	exit
fi

tmpFile="$(mktemp --suffix=.sql)"

printf "/*    Schema definition:    */\n\n" >> "$tmpFile"
cat "$initPath" >> "$tmpFile"
printf "\n\n" >> "$tmpFile"


declare -a elementsOrder=(
	constraints_drop
	indexes_drop
	tables
	indexes_create
	constraints_create
	triggers
	procedures
)

for element in "${elementsOrder[@]}"; do
	if [[ -d "./$element" && $(ls ./$element/*.sql | wc -l) > 0 ]]; then
		echo "Copying $element: "
		printf "/*    $element definition:    */\n\n" >> "$tmpFile"
		for f in $(ls ./$element/*.sql); do
			echo "$f "
			cat "$f" >> "$tmpFile"
			printf "\n" >> "$tmpFile"
		done
		printf "\n\n" >> "$tmpFile"
		echo "done."
	fi
done


echo "Uploading schema on MySQL server..."
res=$(mysql --defaults-file=.my.cnf < "$tmpFile" 2>&1)
if [[ -z "$res" ]]; then
	rm $tmpFile
	echo "Success."
else
	echo "Mysql server has returned a message: '$res'"
	echo "Please review file $tmpFile"
fi
