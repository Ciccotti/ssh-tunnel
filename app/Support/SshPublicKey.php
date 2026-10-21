<?php

namespace App\Support;

class SshPublicKey
{
    private const TYPES = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-rsa'];

    /**
     * Valida uma chave pública OpenSSH ("tipo base64 [comentário]") e devolve
     * só "tipo base64", ou null se for inválida.
     *
     * A chave vai parar na saída do AuthorizedKeysCommand do sshd: descartar o
     * comentário e recusar quebras de linha impede que alguém injete opções ou
     * uma segunda chave sem restrições.
     */
    public static function normalize(string $key): ?string
    {
        $types = implode('|', array_map('preg_quote', self::TYPES));

        if (!preg_match('/^('.$types.') ([A-Za-z0-9+\/]+={0,2})(?: [^\r\n]*)?$/D', trim($key), $matches)) {
            return null;
        }

        [, $type, $base64] = $matches;

        // O conteúdo da chave começa com o próprio tipo (4 bytes de tamanho + texto)
        $blob = base64_decode($base64, true);
        if ($blob === false || strlen($blob) < 4 + strlen($type)) {
            return null;
        }

        $length = unpack('N', substr($blob, 0, 4))[1];
        if ($length !== strlen($type) || substr($blob, 4, $length) !== $type) {
            return null;
        }

        return $type.' '.$base64;
    }
}
