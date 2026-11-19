import base64
import json
import os
import re
import struct
import urllib.request
import zlib


DIAGRAM_WIDTHS = {
    "simplified-class-diagram": 3600,
    "detailed-class-diagram": 4800,
    "supplier-activity-diagram": 3600,
    "vendor-activity-diagram": 3600,
    "admin-activity-diagram": 3600,
}


def set_png_dpi(data, dpi=300):
    signature = b"\x89PNG\r\n\x1a\n"
    if not data.startswith(signature):
        raise ValueError("Rendered PNG has an invalid signature")

    pixels_per_meter = round(dpi / 0.0254)
    chunk_data = b"pHYs" + struct.pack(">IIB", pixels_per_meter, pixels_per_meter, 1)
    physical_resolution = (
        struct.pack(">I", len(chunk_data) - 4)
        + chunk_data
        + struct.pack(">I", zlib.crc32(chunk_data) & 0xFFFFFFFF)
    )
    chunks = [signature]
    offset = len(signature)
    resolution_written = False

    while offset < len(data):
        if offset + 12 > len(data):
            raise ValueError("Rendered PNG contains an incomplete chunk")

        chunk_length = struct.unpack_from(">I", data, offset)[0]
        chunk_end = offset + 12 + chunk_length
        if chunk_end > len(data):
            raise ValueError("Rendered PNG contains an incomplete chunk")

        chunk_type = data[offset + 4 : offset + 8]
        if chunk_type != b"pHYs":
            if chunk_type == b"IDAT" and not resolution_written:
                chunks.append(physical_resolution)
                resolution_written = True
            chunks.append(data[offset:chunk_end])
        elif not resolution_written:
            chunks.append(physical_resolution)
            resolution_written = True

        offset = chunk_end

    if not resolution_written:
        raise ValueError("Rendered PNG is missing its image data")
    return b"".join(chunks)


def load_diagrams(source_path):
    with open(source_path, encoding="utf-8") as source_file:
        source = source_file.read()

    pattern = re.compile(
        r"^### ([a-z0-9-]+)\s*\n\s*```mermaid\s*\n(.*?)\n```",
        re.MULTILINE | re.DOTALL,
    )
    diagrams = pattern.findall(source)
    if not diagrams:
        raise ValueError(f"No Mermaid diagrams found in {source_path}")

    unknown = [name for name, _ in diagrams if name not in DIAGRAM_WIDTHS]
    if unknown:
        raise ValueError(f"Unknown diagram section(s): {', '.join(unknown)}")
    return diagrams


def save_render(url, output_path, timeout):
    request = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
    with urllib.request.urlopen(request, timeout=timeout) as response:
        data = response.read()
    if output_path.lower().endswith(".png"):
        data = set_png_dpi(data)
    with open(output_path, "wb") as output_file:
        output_file.write(data)
    return len(data)


def generate_all():
    script_dir = os.path.dirname(__file__)
    out_dir = os.path.join(script_dir, "..", "diagrams")
    source_path = os.path.join(out_dir, "vendlink-diagrams.md")
    os.makedirs(out_dir, exist_ok=True)

    for name, code in load_diagrams(source_path):
        encoded = base64.urlsafe_b64encode(
            json.dumps({"code": code, "mermaid": {"theme": "default"}}).encode("utf-8")
        ).decode("utf-8")

        for extension, url, timeout in (
            ("svg", f"https://mermaid.ink/svg/{encoded}", 30),
            ("png", f"https://mermaid.ink/img/{encoded}?type=png&bgColor=ffffff&width={DIAGRAM_WIDTHS[name]}", 45),
        ):
            output_path = os.path.join(out_dir, f"{name}.{extension}")
            try:
                size = save_render(url, output_path, timeout)
                print(f"[{extension.upper()}] Saved: {output_path} ({size} bytes)")
            except Exception as error:
                print(f"[{extension.upper()}] Error {name}: {error}")

    print("Diagram rendering complete.")


if __name__ == "__main__":
    generate_all()
