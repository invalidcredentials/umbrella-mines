#pragma once
#include <stddef.h>
#include <stdint.h>

/**
 * AshMaize C API for PHP FFI
 *
 * This header defines the C interface for the AshMaize proof-of-work algorithm.
 * Used by PHP FFI to call the Rust implementation directly.
 */

/**
 * Opaque context type holding the ROM
 *
 * Created by ash_new(), used by ash_hash(), freed by ash_free()
 */
typedef struct ash_ctx ash_ctx_t;

/**
 * Initialize AshMaize ROM with no_pre_mine seed
 *
 * Creates a 1 GiB ROM using the provided seed. This is expensive (takes seconds)
 * but only needs to be done once per challenge.
 *
 * @param seed Pointer to seed bytes (no_pre_mine hex decoded)
 * @param seed_len Length of seed in bytes (typically 32)
 * @return Pointer to context, or NULL on failure
 *
 * Example:
 *   uint8_t seed[32] = { ... };
 *   ash_ctx_t* ctx = ash_new(seed, 32);
 */
ash_ctx_t* ash_new(const uint8_t* seed, size_t seed_len);

/**
 * Compute 64-byte AshMaize hash
 *
 * Hashes the input preimage using the ROM from the context.
 * Fast operation, can be called millions of times with same context.
 *
 * @param ctx Context from ash_new()
 * @param input Preimage bytes (nonce + address + challenge_id + ...)
 * @param input_len Length of input in bytes
 * @param out64 Output buffer, must have space for 64 bytes
 *
 * Example:
 *   uint8_t hash_output[64];
 *   ash_hash(ctx, preimage, preimage_len, hash_output);
 */
void ash_hash(ash_ctx_t* ctx, const uint8_t* input, size_t input_len, uint8_t* out64);

/**
 * Free AshMaize context and ROM
 *
 * Releases all memory associated with the context (~1 GiB).
 * Context must not be used after this call.
 *
 * @param ctx Context to free
 *
 * Example:
 *   ash_free(ctx);
 *   ctx = NULL;
 */
void ash_free(ash_ctx_t* ctx);
