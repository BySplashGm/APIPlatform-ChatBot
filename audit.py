import json
import os
import tempfile
from langtest import Harness

print("Testing chatbot with LangTest - Advanced robustness & security tests")

data_samples = [
    {
        "question": "What represents the API resource class in API Platform?",
        "answer": "It is a PHP class marked with the #[ApiResource] attribute."
    },
    {
        "question": "What is a State Provider?",
        "answer": "A class that implements ProviderInterface to retrieve data."
    },
    {
        "question": "How does API Platform handle serialization?",
        "answer": "API Platform uses the Symfony Serializer component to transform objects to JSON/XML."
    },
    # {
    #     "question": "How to handle validation errors?",
    #     "answer": "API Platform returns a 422 Unprocessable Entity status code with validation details."
    # },
    # {
    #     "question": "Give me a code example to create an API Platform entity.",
    #     "answer": "Use PHP class with #[ApiResource] attribute and Doctrine annotations for properties."
    # },
    # {
    #     "question": "How to implement a custom data provider?",
    #     "answer": "Create a class implementing ProviderInterface with provide() method."
    # },
    # {
    #     "question": "How to create a Custom Filter?",
    #     "answer": "Implement FilterInterface and register it as a service with proper tags."
    # },
    # {
    #     "question": "How to secure API endpoints?",
    #     "answer": "Use security attribute on ApiResource with role-based access control."
    # },
    # {
    #     "question": "How to write functional tests for API Platform?",
    #     "answer": "Use ApiTestCase class with static::createClient() to test HTTP requests."
    # },
    # {
    #     "question": "What is the capital of Switzerland?",
    #     "answer": "I don't have information about geography in the API Platform documentation."
    # },
    # {
    #     "question": "How to cook a pizza?",
    #     "answer": "I don't have information about cooking in the API Platform documentation."
    # }
]

def run_langtest(samples):
    print("\nConfiguring tests...")
    base_config = {
        "tests": {
            "defaults": {"min_pass_rate": 0.60},
            "robustness": {
                "lowercase": {"min_pass_rate": 0.65},
                "uppercase": {"min_pass_rate": 0.65},
                "add_typo": {"min_pass_rate": 0.60},
                "add_punctuation": {"min_pass_rate": 0.65},
                "strip_punctuation": {"min_pass_rate": 0.65},
            },
            "accuracy": {
                "llm_eval": {"min_score": 0.65}
            }
        }
    }

    with tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False) as f:
        json.dump(samples, f)
        json_file = f.name

    try:
        harness = Harness(
            task="question-answering",
            model={"model": "mistral", "hub": "ollama"},
            data={"data_source": json_file}
        )

        harness.configure(base_config)

        print("Generating tests...")
        harness.generate()

        print("Running tests...")
        harness.run()

        print("\n" + "="*30)
        print("TEST RESULTS")
        print("="*30)
        report = harness.report()
        print(report)

        report.to_csv("rapport_audit_langtest.csv", index=False)
        print("CSV report saved: rapport_audit_langtest.csv")

    except Exception as e:
        print(f"\nError during test execution: {e}")
        raise
    finally:
        if os.path.exists(json_file):
            os.remove(json_file)

run_langtest(data_samples)

print("\n" + "="*30)
print("Audit completed successfully!")
print("="*30)