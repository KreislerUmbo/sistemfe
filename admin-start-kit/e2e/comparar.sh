#!/bin/sh
# Uso: ./comparar.sh <ui> — captura y compara contra resultados/payload-main.json
RECORRIDO_USUARIO=admin@agencia-demo.test RECORRIDO_CLAVE=123456 node payload-edicion-venta.mjs --ui "$1" --salida resultados/payload-actual.json || exit 1
node -e "
const a=require('./resultados/payload-main.json'),b=require('./resultados/payload-actual.json');let ok=true;
for(const k of Object.keys(a)){ if(!b[k]){console.log('FALTA',k);ok=false;continue}
 const sa=JSON.stringify(a[k]),sb=JSON.stringify(b[k]); if(sa===sb){console.log('IGUAL',k);continue}
 ok=false;console.log('DIFIERE',k);const ks=new Set([...Object.keys(a[k]),...Object.keys(b[k])]);
 for(const f of ks){const x=JSON.stringify(a[k][f]),y=JSON.stringify(b[k][f]);if(x!==y)console.log('   ',f,'\n      main  :',(x||'').slice(0,300),'\n      nuevo :',(y||'').slice(0,300))}}
process.exit(ok?0:1)"
