from reportlab.lib.pagesizes import letter
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_JUSTIFY, TA_LEFT

doc = SimpleDocTemplate("Semantic_Search_Fix_Proposal.pdf", pagesize=letter,
                        rightMargin=72, leftMargin=72,
                        topMargin=72, bottomMargin=18)

styles = getSampleStyleSheet()
styles.add(ParagraphStyle(name='Justify', alignment=TA_JUSTIFY, fontSize=11, leading=14))
styles.add(ParagraphStyle(name='SubHeading', fontSize=14, leading=16, spaceAfter=10, spaceBefore=15, fontName='Helvetica-Bold'))

Story = []

title_style = styles['Title']
title_style.fontName = 'Helvetica-Bold'
title_style.fontSize = 18

Story.append(Paragraph("Semantic Search Issue and Proposed Solution", title_style))
Story.append(Spacer(1, 20))

Story.append(Paragraph("The Problem", styles['SubHeading']))
text = """
The current search system is effectively acting as a 'Keyword Search', even though it is built on a vector embeddings model. 
<br/><br/>
This is happening because of recent updates made to prevent completely out-of-domain queries (e.g., searching for "Bill Gates" and getting Pakistan politics). To stop these false positives, <b>very strict keyword-presence checks and LLM-based validation rules</b> were added.
<br/><br/>
Specifically:
<br/>
1. <b>Strict LLM Validation:</b> The AI is explicitly instructed to assign a score of 0-3 if the search topic or person is "NOT mentioned in the result". Any result scoring under 0.3 is automatically filtered out.
<br/>
2. <b>Keyword Presence Penalty:</b> A penalty is artificially subtracted from the search score if the exact word isn't found in the text.
<br/><br/>
Because of these rigid rules, valid semantic matches are being thrown out. For example, if you search for "Constitution of Pakistan", the system might find a highly relevant video discussing "laws, amendments, and legal rights". However, because the exact word "Constitution" is missing from the transcript, the LLM gives it a low score and drops it entirely.
"""
Story.append(Paragraph(text, styles['Justify']))

Story.append(Paragraph("The Solution", styles['SubHeading']))
text = """
To restore true meaning-based search while still protecting against completely irrelevant queries, we need to relax the strict keyword-matching prompts in the main testing file: <b>Clip matter py embeding/embeddings_test.py</b>.
<br/><br/>
<b>1. Relax the LLM Reranker Prompt:</b><br/>
Instead of instructing the AI to penalize missing words, we will instruct it to focus on <i>meaning</i>:
<br/>
<i>"If the result discusses the exact meaning, concept, or underlying intent of the query, give it a high score (7-10), even if the specific words aren't used. Only give a low score (0-3) if the meaning is completely unrelated."</i>
<br/><br/>
<b>2. Adjust the Pre-Validation Prompt:</b><br/>
We will update the <code>validate_query_relevance</code> function prompt to explicitly tell the AI:
<br/>
<i>"Do not require exact keyword matches. A query about 'Democracy' is highly relevant to a result discussing 'elections, voting, rights, and parliament'. Assess the conceptual meaning. Only reject if the topic is completely out-of-domain."</i>
<br/><br/>
<b>3. Adjust the Presence Penalty:</b><br/>
We will reduce the artificial penalty applied to queries where the exact word is missing. This ensures strong semantic matches aren't pushed below the visibility threshold.
<br/><br/>
By implementing these changes, the system will start understanding the <i>intent</i> behind the searches (like "Democracy" or "Education Reform") and will show relevant speeches even if those exact words were never spoken.
"""
Story.append(Paragraph(text, styles['Justify']))

doc.build(Story)
print("PDF created successfully: Semantic_Search_Fix_Proposal.pdf")
