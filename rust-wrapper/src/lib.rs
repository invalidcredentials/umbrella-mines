use std::slice;
use std::sync::Arc;
use ashmaize::{Rom, RomGenerationType, hash as ashmaize_hash};

/// Opaque context holding the ROM
#[repr(C)]
pub struct ash_ctx {
    rom: Arc<Rom>,
}

/// Initialize AshMaize ROM with the no_pre_mine seed
///
/// # Arguments
/// * `seed` - Pointer to seed bytes (no_pre_mine from challenge)
/// * `seed_len` - Length of seed in bytes
///
/// # Returns
/// Pointer to ash_ctx or null on failure
///
/// # Safety
/// Caller must ensure seed pointer is valid for seed_len bytes
#[no_mangle]
pub unsafe extern "C" fn ash_new(seed: *const u8, seed_len: usize) -> *mut ash_ctx {
    if seed.is_null() {
        return std::ptr::null_mut();
    }

    let seed_bytes = slice::from_raw_parts(seed, seed_len);

    // AshMaize configuration per Scavenger Mine spec (whitepaper page 18)
    // ROM_SIZE = 1073741824 (1 GiB)
    // pre_size = 16777216 (16 MiB)
    // mixing_numbers = 4
    let rom_size = 1_073_741_824;
    let pre_size = 16_777_216;

    // Generate ROM using TwoStep per whitepaper spec
    let rom = Rom::new(
        seed_bytes,
        RomGenerationType::TwoStep {
            pre_size,
            mixing_numbers: 4,
        },
        rom_size
    );

    let ctx = ash_ctx {
        rom: Arc::new(rom),
    };

    Box::into_raw(Box::new(ctx))
}

/// Compute 64-byte AshMaize hash for the given preimage
///
/// # Arguments
/// * `ctx` - Context from ash_new()
/// * `input` - Preimage bytes (nonce + address + challenge_id + ...)
/// * `input_len` - Length of preimage
/// * `out64` - Output buffer for 64-byte hash
///
/// # Safety
/// Caller must ensure:
/// - ctx is valid (from ash_new)
/// - input pointer is valid for input_len bytes
/// - out64 buffer has space for 64 bytes
#[no_mangle]
pub unsafe extern "C" fn ash_hash(
    ctx: *mut ash_ctx,
    input: *const u8,
    input_len: usize,
    out64: *mut u8,
) {
    if ctx.is_null() || input.is_null() || out64.is_null() {
        return;
    }

    let ctx = &*ctx;
    let preimage = slice::from_raw_parts(input, input_len);

    // AshMaize parameters per spec:
    // nb_loops = 8
    // nb_instrs = 256
    let digest = ashmaize_hash(preimage, &*ctx.rom, 8, 256);

    // Copy 64-byte digest to output
    let output = slice::from_raw_parts_mut(out64, 64);
    output.copy_from_slice(&digest);
}

/// Free AshMaize context and ROM
///
/// # Safety
/// Caller must ensure ctx was created by ash_new and not already freed
#[no_mangle]
pub unsafe extern "C" fn ash_free(ctx: *mut ash_ctx) {
    if !ctx.is_null() {
        drop(Box::from_raw(ctx));
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_basic_hash() {
        unsafe {
            let seed = b"test_seed_for_rom_generation_32b";
            let ctx = ash_new(seed.as_ptr(), seed.len());
            assert!(!ctx.is_null());

            let input = b"test_preimage";
            let mut output = [0u8; 64];
            ash_hash(ctx, input.as_ptr(), input.len(), output.as_mut_ptr());

            // Verify we got some output
            assert_ne!(output, [0u8; 64]);

            ash_free(ctx);
        }
    }
}
