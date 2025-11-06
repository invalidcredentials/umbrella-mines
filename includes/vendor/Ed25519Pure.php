<?php
/**
 * Ed25519Pure.php
 *
 * Minimal, pure-PHP (BCMath) implementation of Ed25519 basepoint multiplication
 * for environments where ext/sodium doesn't expose ed25519 core functions and FFI is blocked.
 *
 * ⚠️ Performance: slow (intended for occasional key derivations, not batch ops).
 * ✅ Compatibility: produces the same compressed public key as libsodium for clamped scalars.
 *
 * API:
 *   - Ed25519Pure::ge_scalarmult_base_noclamp(string $k_le): string  // 32-byte compressed A = [k]B
 *   - Ed25519Pure::A_from_kL(string $kL): string                      // alias; $kL must be clamped 32 bytes
 *
 * Implementation notes:
 *   - Field arithmetic is done in decimal strings via BCMath modulo p = 2^255 - 19.
 *   - Uses extended Edwards coordinates and the Hisil et al. addition/doubling formulas.
 *   - Scalar multiply: left-to-right binary double-and-add over 256 bits.
 *   - Compression: encodes y (LE) with sign of x in top bit of last byte.
 *
 * References:
 *   - "Twisted Edwards Curves Revisited", Hisil, Wong, Carter, Dawson (2008)
 *   - Ed25519 parameters (p, d, basepoint)
 */

final class Ed25519Pure
{
    // Prime modulus p = 2^255 - 19
    private const P_DEC = '57896044618658097711785492504343953926634992332820282019728792003956564819949';
    // Edwards d = -121665/121666 mod p
    private const D_DEC = '37095705934669439343138083508754565189542113879843219016388785533085940283555';
    private const TWO_D_DEC = '74191411869338878686276167017509130379084227759686438032777571066171880567110';

    // Basepoint (Bx, By) in decimal
    private const Bx_DEC = '15112221349535400772501151409588531511454012693041857206046113283949847762202';
    private const By_DEC = '46316835694926478169428394003475163141307993866256225615783033603165251855960';

    // Helpers: decimal "0" and "1"
    private const ZERO = '0';
    private const ONE  = '1';

    /** Public: compute A = [k]B compressed (32 bytes), k is 32-byte little-endian (already clamped) */
    public static function ge_scalarmult_base_noclamp(string $k_le): string
    {
        if (strlen($k_le) !== 32) {
            throw new \InvalidArgumentException('k must be 32 bytes (little-endian)');
        }

        // Extended coords for basepoint
        $B = self::point_from_affine(self::Bx_DEC, self::By_DEC);

        // Identity point in extended coords: (0,1,1,0)
        $R = ['X'=>self::ZERO, 'Y'=>self::ONE, 'Z'=>self::ONE, 'T'=>self::ZERO];

        // Double-and-add from msb to lsb (bits 255..0)
        for ($bit = 255; $bit >= 0; $bit--) {
            $R = self::point_double($R);
            $b = (ord($k_le[$bit >> 3]) >> ($bit & 7)) & 1;
            if ($b) {
                $R = self::point_add($R, $B);
            }
        }

        // Compress: y (LE) with sign of x in top bit
        [$x, $y] = self::to_affine($R);
        $y_le = self::dec_to_le($y, 32);
        $x_parity = (int) bcmod($x, '2');

        $last = ord($y_le[31]);
        $last = $x_parity ? ($last | 0x80) : ($last & 0x7F);
        $y_le[31] = chr($last);
        return $y_le;
    }

    /** Alias for clarity with CIP-1852 naming */
    public static function A_from_kL(string $kL): string
    {
        return self::ge_scalarmult_base_noclamp($kL);
    }

    // ----- Point/field arithmetic -----

    private static function point_from_affine(string $x, string $y): array
    {
        $x = self::modp($x);
        $y = self::modp($y);
        $Z = self::ONE;
        $T = self::mul_modp($x, $y);
        return ['X'=>$x, 'Y'=>$y, 'Z'=>$Z, 'T'=>$T];
    }

    private static function point_add(array $P, array $Q): array
    {
        $X1=$P['X']; $Y1=$P['Y']; $Z1=$P['Z']; $T1=$P['T'];
        $X2=$Q['X']; $Y2=$Q['Y']; $Z2=$Q['Z']; $T2=$Q['T'];

        $Y1mX1 = self::sub_modp($Y1, $X1);
        $Y1pX1 = self::add_modp($Y1, $X1);
        $Y2mX2 = self::sub_modp($Y2, $X2);
        $Y2pX2 = self::add_modp($Y2, $X2);

        $A = self::mul_modp($Y1mX1, $Y2mX2);
        $B = self::mul_modp($Y1pX1, $Y2pX2);
        $C = self::mul_modp(self::mul_modp(self::TWO_D_DEC, $T1), $T2);
        $D = self::mul_modp(self::add_modp($Z1, $Z1), $Z2);

        $E = self::sub_modp($B, $A);
        $F = self::sub_modp($D, $C);
        $G = self::add_modp($D, $C);
        $H = self::add_modp($B, $A);

        $X3 = self::mul_modp($E, $F);
        $Y3 = self::mul_modp($G, $H);
        $T3 = self::mul_modp($E, $H);
        $Z3 = self::mul_modp($F, $G);

        return ['X'=>$X3, 'Y'=>$Y3, 'Z'=>$Z3, 'T'=>$T3];
    }

    private static function point_double(array $P): array
    {
        $X1=$P['X']; $Y1=$P['Y']; $Z1=$P['Z'];

        $A = self::mul_modp($X1, $X1);             // A = X1^2
        $B = self::mul_modp($Y1, $Y1);             // B = Y1^2
        $C = self::mul_modp('2', self::mul_modp($Z1, $Z1)); // C = 2*Z1^2
        $D = self::sub_modp(self::ZERO, $A);       // D = -A
        $E = self::sub_modp(self::sub_modp(self::mul_modp(self::add_modp($X1, $Y1), self::add_modp($X1, $Y1)), $A), $B); // (X1+Y1)^2 - A - B
        $G = self::add_modp($D, $B);               // G = D + B
        $F = self::sub_modp($G, $C);               // F = G - C
        $H = self::sub_modp($D, $B);               // H = D - B

        $X3 = self::mul_modp($E, $F);
        $Y3 = self::mul_modp($G, $H);
        $T3 = self::mul_modp($E, $H);
        $Z3 = self::mul_modp($F, $G);

        return ['X'=>$X3, 'Y'=>$Y3, 'Z'=>$Z3, 'T'=>$T3];
    }

    private static function to_affine(array $P): array
    {
        $Zinv = self::inv_modp($P['Z']);
        $x = self::mul_modp($P['X'], $Zinv);
        $y = self::mul_modp($P['Y'], $Zinv);
        return [$x, $y];
    }

    // ----- Field ops modulo p (decimal strings) -----

    private static function modp(string $a): string {
        $a = bcmod($a, self::P_DEC);
        if (bccomp($a, self::ZERO) < 0) {
            $a = bcadd($a, self::P_DEC);
        }
        return $a;
    }

    private static function add_modp(string $a, string $b): string {
        $s = bcadd($a, $b, 0);
        $s = bcmod($s, self::P_DEC);
        return $s;
    }

    private static function sub_modp(string $a, string $b): string {
        $d = bcsub($a, $b, 0);
        $d = bcmod($d, self::P_DEC);
        if (bccomp($d, self::ZERO) < 0) $d = bcadd($d, self::P_DEC);
        return $d;
    }

    private static function mul_modp(string $a, string $b): string {
        $m = bcmul($a, $b, 0);
        $m = bcmod($m, self::P_DEC);
        return $m;
    }

    private static function inv_modp(string $a): string {
        // Extended Euclidean algorithm to find a^{-1} mod p
        $t = '0'; $newt = '1';
        $r = self::P_DEC; $newr = self::modp($a);

        while ($newr !== '0') {
            $q = bcdiv($r, $newr, 0);
            $tmp = $newt; $newt = bcsub($t, bcmul($q, $newt, 0), 0); $t = $tmp;
            $tmp = $newr; $newr = bcsub($r, bcmul($q, $newr, 0), 0); $r = $tmp;
        }
        if ($r !== '1') {
            throw new \RuntimeException('Element not invertible mod p');
        }
        if (bccomp($t, self::ZERO) < 0) $t = bcadd($t, self::P_DEC);
        return bcmod($t, self::P_DEC);
    }

    // ----- Encoding helpers -----

    private static function dec_to_le(string $dec, int $len): string
    {
        $bytes = [];
        $n = self::modp($dec);
        for ($i = 0; $i < $len; $i++) {
            $byte = (int) bcmod($n, '256');
            $bytes[] = chr($byte);
            $n = bcdiv($n, '256', 0);
        }
        return implode('', $bytes);
    }
}
