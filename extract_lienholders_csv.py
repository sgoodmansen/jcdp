import csv
from pathlib import Path

import openpyxl


source = Path(r"C:\!SG\DMV Temp\Lienholders.xlsx")
output_dir = Path(r"C:\!SG\BYU-I\SPRING 2026\Tech Dev\ShareTrip-1")
workbook = openpyxl.load_workbook(source, data_only=True)

for sheet_name, filename in [
    ("Lienholders", "lienholders_import.csv"),
    ("Phones", "phones_import.csv"),
]:
    worksheet = workbook[sheet_name]
    output_path = output_dir / filename
    with output_path.open("w", newline="", encoding="utf-8-sig") as handle:
        writer = csv.writer(handle)
        for row in worksheet.iter_rows(values_only=True):
            writer.writerow(["" if value is None else value for value in row])

    print(f"Wrote {output_path}")
