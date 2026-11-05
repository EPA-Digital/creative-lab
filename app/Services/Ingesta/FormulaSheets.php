<?php

namespace App\Services\Ingesta;

use InvalidArgumentException;

/**
 * Convierte la fórmula de Google Sheets con la que un equipo ya clasifica
 * sus nombres -- =IFS(REGEXMATCH(A2,"see"),"Awareness", ...) o IF anidados
 * -- en reglas "patrón → valor" de ReglasNomenclatura, en el mismo orden
 * (la primera que coincide gana, igual que en Sheets).
 *
 * Sheets usa RE2 y acá corre PCRE: para patrones normales es lo mismo; uno
 * que PCRE no entienda se rechaza con el patrón en el mensaje.
 */
class FormulaSheets
{
    /** @return list<array{patron: string, valor: string}> */
    public static function convertir(string $formula): array
    {
        // REGEXMATCH(<celda o LOWER(celda)>, "patrón") seguido de , "valor"
        $re = '/REGEXMATCH\s*\(\s*(?:[^,()]+|\w+\([^()]*\))\s*,\s*"((?:[^"]|"")*)"\s*\)\s*,\s*"((?:[^"]|"")*)"/i';
        if (! preg_match_all($re, $formula, $coincidencias, PREG_SET_ORDER)) {
            throw new InvalidArgumentException('No encontré ningún REGEXMATCH(celda, "patrón"), "valor" en la fórmula. Pega la fórmula completa, con el =.');
        }

        $reglas = [];
        foreach ($coincidencias as [, $patron, $valor]) {
            $patron = str_replace('""', '"', $patron);
            // (?i) de Sheets sobra: acá nunca se distinguen mayúsculas.
            $patron = preg_replace('/^\(\?i\)/', '', $patron);
            if (@preg_match('~'.str_replace('~', '\~', $patron).'~iu', '') === false) {
                throw new InvalidArgumentException("El patrón \"{$patron}\" de la fórmula no es compatible. Revísalo o escríbelo de otra forma.");
            }
            $reglas[] = ['patron' => $patron, 'valor' => trim(str_replace('""', '"', $valor))];
        }

        return $reglas;
    }
}
