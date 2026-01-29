import pandas as pd
import json
import os

regions = ['cn', 'kr', 'gl', 'tc']
files = ['Action', 'Status']

os.makedirs('json', exist_ok=True)

for region in regions:
    for file in files:
        csv_path = f'{region}/{file}.csv'
        if os.path.exists(csv_path):
            df = pd.read_csv(csv_path)
            # Extract ID and Name, skip metadata rows
            data = df.iloc[3:, :2]
            data.columns = ['ID', 'Name']
            
            # Save as region_File.json
            output_name = f'json/{region}_{file}.json'
            data.to_json(output_name, orient='records', indent=4, force_ascii=False)
            print(f"Processed {output_name}")
