Organization demo, form service_report; submissions 100029, submission_values 2325957, tasks 40575, renditions 71109, workflow_runs 38051, mail_messages 16926; aggregate slice: 10000 newest submissions (submitted_at >= 2026-09-09 12:08:25); keys {"number":"net_total","date":"service_date","choice":"service_type","choice_value":"maintenance"}; median of 5 runs.

| Query | Median ms | Rows | Scans | Full scans of large tables |
|---|---:|---:|---|---|
| kpi: submissions in range | 0.66 | 1 | Index Scan using submissions_org_submitted_at_idx on submissions | none |
| kpi: pending reviews | 2.19 | 1 | Bitmap Heap Scan on tasks; Bitmap Index Scan using tasks_organization_id_status_due_at_index | none |
| kpi: stale drafts | 0.6 | 1 | Index Only Scan using submissions_org_drafts_updated_at_idx on submissions | none |
| kpi: documents sent | 0.16 | 1 | Index Scan using mail_messages_organization_id_status_created_at_index on mail_messages | none |
| chart: submissions per form and day | 2.24 | 7 | Index Scan using submissions_org_submitted_at_idx on submissions | none |
| table: workflow failures | 0.49 | 10 | Bitmap Heap Scan on workflow_runs; Bitmap Index Scan using workflow_runs_organization_id_status_index | none |
| table: review queue (reviewer) | 4.41 | 8 | Bitmap Heap Scan on tasks; Bitmap Index Scan using tasks_assignee_user_id_status_index; Bitmap Index Scan using tasks_assignee_group_id_status_index; Index Scan using submissions_pkey on submissions; Seq Scan on tasks; Index Scan using group_user_organization_id_user_id_index on group_user | Seq Scan on tasks |
| table: recent submissions (sees all) | 0.03 | 10 | Index Scan using submissions_org_submitted_at_idx on submissions | none |
| table: recent submissions (reviewer visibility) | 4.05 | 10 | Index Scan using submissions_org_submitted_at_idx on submissions; Seq Scan on tasks; Index Scan using group_user_organization_id_user_id_index on group_user | Seq Scan on tasks |
| table: draft forms | 0.02 | 8 | Seq Scan on forms | none |
| table: recently published | 0.03 | 8 | Index Scan using form_versions_organization_id_form_id_index on form_versions | none |
| values: number range, newest first | 0.98 | 50 | Index Scan using submission_values_org_form_key_submitted_at_idx on submission_values | none |
| values: date range, newest first | 3.6 | 50 | Index Scan using submission_values_organization_id_form_id_key_value_date_index on submission_values | none |
| values: choice equals, newest first | 0.08 | 50 | Index Scan using submission_values_org_form_key_submitted_at_idx on submission_values | none |
| explorer: page of a form, newest first | 2.58 | 25 | Index Scan using submissions_org_form_submitted_uuid_idx on submissions; Bitmap Heap Scan on submission_values; Bitmap Index Scan using submission_values_submission_key_idx | none |
| explorer: page, reviewer visibility | 7.43 | 25 | Index Scan using submissions_org_form_submitted_uuid_idx on submissions; Bitmap Heap Scan on submission_values; Bitmap Index Scan using submission_values_submission_key_idx; Seq Scan on tasks; Index Scan using group_user_organization_id_user_id_index on group_user | Seq Scan on tasks |
| explorer: page filtered by a number range | 11.24 | 25 | Index Scan using submissions_org_form_submitted_uuid_idx on submissions; Index Scan using submission_values_submission_key_idx on submission_values; Bitmap Heap Scan on submission_values; Bitmap Index Scan using submission_values_submission_key_idx | none |
| explorer: page filtered by a choice | 4.72 | 25 | Index Scan using submissions_org_form_submitted_uuid_idx on submissions; Index Scan using submission_values_submission_key_idx on submission_values; Bitmap Heap Scan on submission_values; Bitmap Index Scan using submission_values_submission_key_idx | none |
| report: sum of a number grouped by a choice since a date | 58.91 | 4 | Bitmap Heap Scan on submissions; Bitmap Index Scan using submissions_org_submitted_at_idx; Index Scan using submission_values_submission_key_idx on submission_values | none |

| Widget data (uncached, PHP) | Median ms |
|---|---:|
| KPIs (4 counts) | 4.65 |
| Submissions over time per form | 1.99 |
| Workflow failures | 1.48 |
| Review queue (reviewer) | 6.71 |
| Recent submissions (sees all) | 1.79 |
| Recent submissions (reviewer) | 6.02 |
| Draft forms | 0.53 |
| Recently published | 2.29 |
