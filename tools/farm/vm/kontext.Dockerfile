# Test-Farm (tools/farm/vm.sh): gibt genau den Bau-Kontext aus, den `docker build .`
# sieht (die .dockerignore im Wurzelverzeichnis entscheidet). vm.sh legt ihn als
# quelle/ neben die lokale App, die der Supervisor in der VM baut.
FROM scratch
COPY . /
