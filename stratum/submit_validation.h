#ifndef STRATUM_SUBMIT_VALIDATION_H
#define STRATUM_SUBMIT_VALIDATION_H

#include "json.h"
#include <cstddef>
#include <cstring>

// Validate decoded JSON before C-string copies, byte reversal or hex decoding.
// JSON strings can contain embedded NULs; strlen alone is not their wire length.
namespace stratum_input {

inline bool string_value(const json_value *value, size_t maximum)
{
    return value && value->type == json_string && value->u.string.ptr &&
        value->u.string.length <= maximum &&
        !std::memchr(value->u.string.ptr, '\0', value->u.string.length);
}

inline bool string_params(const json_value *params)
{
    if (!params || params->type != json_array) return false;
    for (unsigned int i = 0; i < params->u.array.length; ++i)
        if (!string_value(params->u.array.values[i], 65535)) return false;
    return true;
}

inline const json_value *member(const json_value *message, const char *name)
{
    if (!message || message->type != json_object) return nullptr;
    for (unsigned int i = 0; i < message->u.object.length; ++i)
        if (!std::strcmp(message->u.object.values[i].name, name))
            return message->u.object.values[i].value;
    return nullptr;
}

inline bool subscribe(const json_value *params)
{
    if (!params || params->type != json_array) return false;
    // The optional agent/session fields may be null. Additional extension
    // parameters are ignored by this implementation, as they were before.
    for (unsigned int i = 0; i < params->u.array.length && i < 2; ++i)
        if (params->u.array.values[i]->type != json_null &&
            !string_value(params->u.array.values[i], 1023)) return false;
    return true;
}

inline int hex_digit(char c)
{
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'a' && c <= 'f') return c - 'a' + 10;
    if (c >= 'A' && c <= 'F') return c - 'A' + 10;
    return -1;
}

inline bool hex_value(const json_value *value, size_t minimum, size_t maximum,
    bool whole_bytes = true)
{
    if (!string_value(value, maximum) || value->u.string.length < minimum ||
        (whole_bytes && value->u.string.length % 2)) return false;
    for (unsigned int i = 0; i < value->u.string.length; ++i)
        if (hex_digit(value->u.string.ptr[i]) < 0) return false;
    return true;
}

inline size_t equihash_solution_bytes(int n, int k)
{
    if (n == 48 && k == 5) return 36;
    if (n == 96 && k == 5) return 68;
    if (n == 125 && k == 4) return 52;
    if (n == 144 && k == 5) return 100;
    if (n == 192 && k == 7) return 400;
    if (n == 200 && k == 9) return 1344;
    return 0;
}

inline bool equihash_solution(const json_value *value, int n, int k)
{
    const size_t bytes = equihash_solution_bytes(n, k);
    if (!bytes) return false;
    const size_t prefix = bytes < 253 ? 1 : 3;
    const size_t length = (prefix + bytes) * 2;
    if (!hex_value(value, length, length)) return false;
    const char *hex = value->u.string.ptr;
    unsigned char decoded[3] = {};
    for (size_t i = 0; i < prefix; ++i)
        decoded[i] = (hex_digit(hex[2 * i]) << 4) | hex_digit(hex[2 * i + 1]);
    // Zcash Stratum carries the canonical CompactSize prefix with the solution.
    return prefix == 1 ? decoded[0] == bytes :
        decoded[0] == 253 && static_cast<size_t>(decoded[1] | (decoded[2] << 8)) == bytes;
}

inline bool equihash_submit(const json_value *params, int n, int k, size_t nonce_bytes)
{
    if (!string_params(params) || params->u.array.length != 5) return false;
    const auto values = params->u.array.values;
    return string_value(values[0], 1023) &&
        hex_value(values[1], 1, 8, false) &&
        hex_value(values[2], 8, 8) &&
        hex_value(values[3], nonce_bytes * 2, nonce_bytes * 2) &&
        equihash_solution(values[4], n, k);
}

inline bool standard_submit(const json_value *params, const char *algo)
{
    if (!string_params(params) || params->u.array.length < 5 ||
        params->u.array.length > 6) return false;
    const auto values = params->u.array.values;
    if (!string_value(values[0], 1023) || !hex_value(values[1], 1, 8, false) ||
        !hex_value(values[2], 0, 30) || !hex_value(values[3], 8, 8) ||
        !hex_value(values[4], 8, 8)) return false;
    if (params->u.array.length == 5) return true;
    if (std::strstr(algo, "sha256")) return hex_value(values[5], 8, 8);
    if (std::strstr(algo, "phi")) return hex_value(values[5], 128, 128);
    return hex_value(values[5], 0, 7, false);
}

inline bool prefixed_hex(const json_value *value, size_t digits)
{
    if (!string_value(value, digits + 2)) return false;
    const size_t offset = value->u.string.length == digits + 2 ? 2 : 0;
    if (value->u.string.length != digits + offset ||
        (offset && std::memcmp(value->u.string.ptr, "0x", 2))) return false;
    for (size_t i = offset; i < value->u.string.length; ++i)
        if (hex_digit(value->u.string.ptr[i]) < 0) return false;
    return true;
}

inline bool kawpow_submit(const json_value *params)
{
    if (!string_params(params) || params->u.array.length != 5) return false;
    const auto values = params->u.array.values;
    return string_value(values[0], 1023) && hex_value(values[1], 1, 8, false) &&
        prefixed_hex(values[2], 16) && prefixed_hex(values[3], 64) &&
        prefixed_hex(values[4], 64);
}

// Only inspect a JSON-RPC envelope as an object. Validate fields before the
// legacy json_get_* accessors, which do not check the JSON value's union tag.
inline bool envelope(const json_value *message)
{
    if (!message || message->type != json_object) return false;
    for (unsigned int i = 0; i < message->u.object.length; ++i) {
        const auto &field = message->u.object.values[i];
        if (std::memchr(field.name, '\0', field.name_length)) return false;
        if (!std::strcmp(field.name, "id")) {
            if (field.value->type != json_null && field.value->type != json_integer &&
                !string_value(field.value, 32)) return false;
        } else if (!std::strcmp(field.name, "method")) {
            if (!string_value(field.value, 128)) return false;
        } else if (!std::strcmp(field.name, "params")) {
            if (field.value->type != json_array) return false;
        }
    }
    return true;
}

} // namespace stratum_input
#endif
